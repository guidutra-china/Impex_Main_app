<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;

class ProformaInvoicePdfTemplate extends AbstractPdfTemplate
{
    /** Máximo de caracteres da descrição do item no PDF. */
    protected const DESCRIPTION_LIMIT = 150;

    protected bool $hideCommission;

    protected bool $withImages;

    protected bool $showProductCode;

    protected bool $showModelNumber;

    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $hideCommission = false,
        bool $withImages = false,
        bool $showProductCode = false,
        bool $showModelNumber = true,
    ) {
        parent::__construct($model, $locale);
        $this->hideCommission = $hideCommission;
        $this->withImages = $withImages;
        $this->showProductCode = $showProductCode;
        $this->showModelNumber = $showModelNumber;
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();
        $picSuffix = $this->withImages ? '-PIC' : '';

        return $reference.$picSuffix.'-v'.$this->getNextVersion().'.pdf';
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
        return 'proforma_invoice_pdf';
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
            'items.supplierCompany',
            'additionalCosts',
            'creator',
        ]);

        $currencyCode = $pi->currency_code ?? 'USD';
        $clientId = $pi->company_id;

        $items = $pi->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode, $clientId) {
            // Model number visível ao cliente: código do cliente (pivot) tem
            // prioridade, como na Commercial Invoice / Packing List.
            $pivot = $item->product?->companies->firstWhere('id', $clientId)?->pivot;

            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'model_number' => $pivot?->external_code ?: ($item->product?->model_number ?? '—'),
                'description' => $this->formatDescription($item->description ?? $item->product?->name ?? '—'),
                'specifications' => $item->specifications,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
                'image' => $this->withImages ? $this->resolveImagePath($item->product?->avatar) : null,
            ];
        });

        $subtotal = $pi->items->sum(fn ($item) => $item->line_total);

        $serviceFees = $pi->additionalCosts
            ->filter(function ($cost) {
                if ($cost->billable_to !== BillableTo::CLIENT) {
                    return false;
                }
                if ($cost->status === AdditionalCostStatus::WAIVED) {
                    return false;
                }
                if ($cost->cost_type === AdditionalCostType::COMMISSION) {
                    if ($this->hideCommission) {
                        return false;
                    }
                    // Embedded commissions are already baked into item unit prices;
                    // listing them again would double-count.
                    if ($cost->commission_mode === CommissionType::EMBEDDED) {
                        return false;
                    }
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
     * Normaliza e limita a descrição para o PDF: espaço após vírgulas/barras
     * (listas como "9560STS,9570STS,..." viram quebráveis — sem isso o DomPDF
     * expande a coluna e empurra Qty/Price/Total para fora da página) e corte
     * em DESCRIPTION_LIMIT caracteres.
     */
    protected function formatDescription(string $description): string
    {
        $breakable = preg_replace('~([,/;])(?=\S)~', '$1 ', $description);

        return \Illuminate\Support\Str::limit($breakable, self::DESCRIPTION_LIMIT);
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
