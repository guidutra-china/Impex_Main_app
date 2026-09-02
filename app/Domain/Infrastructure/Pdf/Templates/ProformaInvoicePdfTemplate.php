<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Pdf\Support\PriceFormula;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class ProformaInvoicePdfTemplate extends AbstractPdfTemplate
{
    protected bool $hideCommission;

    protected bool $withImages;

    protected bool $showProductCode;

    protected bool $showModelNumber;

    // Com valor na declaração de propósito: getDocumentType() é chamado em
    // instâncias criadas sem construtor (DocumentTypeFitsColumnTest), e
    // propriedade tipada sem default explode nesse caminho.
    protected ?string $priceFormula = null;

    protected bool $useCustomPrices = false;

    /** Resolvido em getDocumentData(); effectiveUnitPrice() lê o pivot por ele. */
    private ?ProductIdentityResolver $identity = null;

    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $hideCommission = false,
        bool $withImages = false,
        bool $showProductCode = false,
        bool $showModelNumber = true,
        /** Fórmula aplicada ao preço unitário ("*0.70", "+10"…). */
        ?string $priceFormula = null,
        /** Usa o preço especial cadastrado para o cliente, quando houver. */
        bool $useCustomPrices = false,
        array $options = [],
    ) {
        parent::__construct($model, $locale, $options);
        $this->hideCommission = $hideCommission;
        $this->withImages = $withImages;
        $this->showProductCode = $showProductCode;
        $this->showModelNumber = $showModelNumber;
        $this->priceFormula = blank($priceFormula) ? null : $priceFormula;
        $this->useCustomPrices = $useCustomPrices;
    }

    /**
     * True quando os preços impressos não são os da PI.
     *
     * Documento com preço alterado é arquivado à parte, com versionamento
     * próprio: gerar uma proposta com desconto não pode avançar a versão da
     * PI oficial nem se passar por ela no histórico.
     */
    protected function hasOverriddenPrices(): bool
    {
        return $this->priceFormula !== null || $this->useCustomPrices;
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();
        $picSuffix = $this->withImages ? '-PIC' : '';
        $prefix = $this->hasOverriddenPrices() ? 'Custom-' : '';

        return $prefix.$reference.$picSuffix.'-v'.$this->getNextVersion().'.pdf';
    }

    public function getView(): string
    {
        return 'pdf.proforma-invoice';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('proforma_invoice');
    }

    public function getDocumentType(): string
    {
        return $this->hasOverriddenPrices() ? 'custom_price_pdf' : 'proforma_invoice_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var ProformaInvoice $pi */
        $pi = $this->model;
        $pi->loadMissing([
            'company',
            'contact',
            'inquiry',
            'paymentTerm',
            'quotations',
            'items.product.companies',
            'items.product.attributeValues.categoryAttribute',
            'items.supplierCompany',
            'additionalCosts',
            'creator',
        ]);

        $currencyCode = $pi->currency_code ?? 'USD';

        // PI é faturada direto à empresa — sem conceito de filial (só
        // company_id, sem company_branch_id no schema), então nenhum parent:
        // aqui, diferente do Commercial Invoice/Packing List do embarque.
        $identityResolver = $this->identity = ProductIdentityResolver::forClientCompany(
            company: $pi->company,
            overrides: $this->options,
        );
        $identityResolver->warm($pi->items->map(fn ($item) => $item->product));

        $items = $pi->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode, $identityResolver) {
            $identity = $identityResolver->resolve($item->product, lineDescription: $item->description);

            return [
                'index' => $index + 1,
                // Coluna opt-in e explicitamente interna: segue sendo o SKU.
                'product_code' => $item->product?->sku ?: '—',
                'model_number' => $identity->codeOr('—'),
                'description' => $this->formatDescription($identity->descriptionOr($identity->name) ?? ''),
                'specifications' => $item->specifications,
                'attributes' => $this->productAttributesLine($item->product),
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($this->effectiveUnitPrice($item), $currencyCode),
                'line_total' => $this->formatMoney($this->effectiveUnitPrice($item) * $item->quantity, $currencyCode, 2),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
                'image' => $this->withImages ? $this->resolveImagePath($item->product?->avatar) : null,
            ];
        });

        $subtotal = $pi->items->sum(fn ($item) => $this->effectiveUnitPrice($item) * $item->quantity);

        $serviceFees = $pi->additionalCosts
            ->filter(function ($cost) {
                if ($cost->billable_to !== BillableTo::CLIENT) {
                    return false;
                }
                if ($cost->status === AdditionalCostStatus::WAIVED) {
                    return false;
                }
                // commission_mode is NOT consulted here: the payment schedule
                // charges every client-billable cost regardless of it, so
                // dropping EMBEDDED rows printed a grand total lower than the
                // amount actually invoiced (prod: PI-2026-00078 printed
                // 4,325.06 against 4,544.20 charged). hideCommission stays as
                // the explicit, per-document way to omit the line.
                if ($cost->cost_type === AdditionalCostType::COMMISSION && $this->hideCommission) {
                    return false;
                }

                return true;
            })
            ->map(fn ($cost) => [
                'description' => $cost->description,
                'amount' => $this->formatMoney($cost->amount_in_document_currency, $currencyCode, 2),
                'raw_amount' => $cost->amount_in_document_currency,
            ])
            ->values()
            ->toArray();

        $serviceFeeTotal = array_sum(array_column($serviceFees, 'raw_amount'));
        $grandTotal = $subtotal + $serviceFeeTotal;

        return [
            'proforma_invoice' => [
                'reference' => $pi->reference,
                'client_reference' => $pi->client_reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'valid_until' => $this->formatDate($pi->valid_until),
                'currency_code' => $currencyCode,
                'incoterm' => $pi->incoterm instanceof \BackedEnum ? $pi->incoterm->value : $pi->incoterm,
                'inquiry_reference' => $pi->inquiry?->reference,
                'notes' => $pi->notes,
                'created_by' => $pi->creator?->name,
                'linked_quotations' => $pi->quotations->pluck('reference')->implode(', '),
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
                'legal_name' => $pi->company?->legal_name,
                'address' => $pi->company?->full_address ?? '—',
                'phone' => $pi->company?->phone,
                'email' => $pi->company?->email,
                'tax_id' => $pi->company?->tax_number,
                'contact_name' => $pi->contact?->name,
                'contact_email' => $pi->contact?->email,
            ],
            'items' => $items->toArray(),
            'with_images' => $this->withImages,
            'show_product_code' => $this->showProductCode,
            'show_model_number' => $this->showModelNumber,
            'service_fees' => $serviceFees,
            'totals' => [
                'subtotal' => $this->formatMoney($subtotal, $currencyCode, 2),
                'grand_total' => $this->formatMoney($grandTotal, $currencyCode, 2),
            ],
            'payment_term' => [
                'name' => $pi->paymentTerm?->name,
                'description' => $pi->paymentTerm?->description,
            ],
        ];
    }

    /**
     * Preço unitário impresso: fórmula > preço especial do cliente (quando
     * pedido) > preço da linha da PI.
     *
     * Fórmula e preço especial são exclusivos na tela; a ordem aqui só define
     * o desempate se os dois vierem marcados.
     */
    protected function effectiveUnitPrice($item): int
    {
        $base = (int) $item->unit_price;

        if ($this->priceFormula !== null) {
            return PriceFormula::apply($base, $this->priceFormula);
        }

        if ($this->useCustomPrices) {
            $customPrice = (int) ($this->identity?->pivot($item->product)?->custom_price ?? 0);

            if ($customPrice > 0) {
                return $customPrice;
            }
        }

        return $base;
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
