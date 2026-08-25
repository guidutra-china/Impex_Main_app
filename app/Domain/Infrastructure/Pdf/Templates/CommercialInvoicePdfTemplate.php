<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Logistics\Enums\ImportModality;
use App\Domain\Logistics\Models\Shipment;

class CommercialInvoicePdfTemplate extends AbstractPdfTemplate
{
    private ?ProductIdentityResolver $identity = null;

    public function getView(): string
    {
        return 'pdf.commercial-invoice';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('commercial_invoice');
    }

    public function getDocumentType(): string
    {
        return 'commercial_invoice_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return 'CI-'.$reference.'-v'.$this->getNextVersion().'.pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'companyBranch',
            'items.proformaInvoiceItem.product.companies',
            'items.proformaInvoiceItem.proformaInvoice.paymentTerm',
            'additionalCosts',
        ]);

        // Documento endereçado à filial resolve o pivot da filial primeiro e
        // cai para a matriz — o endereço já segue getDocumentClient(). A
        // preferência de nomenclatura segue a mesma precedência, derivada na
        // mesma chamada para as duas não divergirem.
        $this->identity = ProductIdentityResolver::forClientCompany(
            company: $shipment->getDocumentClient(),
            parent: $shipment->company,
            overrides: $this->options,
        );
        $this->identity->warm(
            $shipment->items->map(fn ($item) => $item->proformaInvoiceItem?->product)
        );

        $currencyCode = $shipment->currency_code ?? 'USD';

        $piReferences = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');

        $paymentTerm = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->paymentTerm)
            ->filter()
            ->first();

        $incoterm = $shipment->incoterm
            ?: $shipment->items
                ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->incoterm)
                ->filter()
                ->first();

        $priceFormula = ($this->options['use_formula'] ?? false)
            ? ($this->options['price_formula'] ?? null)
            : null;
        $useCustomPrices = (bool) ($this->options['use_custom_prices'] ?? true);

        $items = $this->buildInvoiceItems($shipment, $currencyCode, $priceFormula, $useCustomPrices);

        $subtotal = $shipment->items->sum(
            fn ($item) => $this->effectiveUnitPrice($item, $priceFormula, $useCustomPrices) * $item->quantity
        );

        $includeFreight = $this->options['include_freight'] ?? false;

        $freightCosts = $includeFreight
            ? $shipment->additionalCosts
                ->filter(fn ($cost) => $cost->cost_type === AdditionalCostType::FREIGHT)
                ->sum('amount_in_document_currency')
            : 0;

        $grandTotal = $subtotal + $freightCosts;

        $documentDate = $shipment->issue_date ?? $shipment->etd ?? $shipment->created_at ?? now();

        $documentClient = $shipment->getDocumentClient();

        return [
            'shipment' => [
                'reference' => $shipment->reference,
                'origin_port' => $shipment->origin_port,
                'destination_port' => $shipment->destination_port,
                'date' => $this->formatDate($documentDate),
                'etd' => $this->formatDate($shipment->etd),
                'currency_code' => $currencyCode,
                'pi_references' => $piReferences,
                'incoterm' => $incoterm instanceof \BackedEnum ? $incoterm->value : $incoterm,
            ],
            'client' => [
                'name' => filled($documentClient->legal_name) ? $documentClient->legal_name : ($documentClient->name ?? '—'),
                'legal_name' => null,
                'address' => $documentClient->full_address ?? '—',
                'phone' => $documentClient->phone,
                'email' => $documentClient->email,
                'tax_id' => $documentClient->tax_number,
            ],
            'items' => $items,
            // Coluna NCM só aparece quando algum item tem NCM do cliente —
            // clientes fora do Brasil não veem uma coluna vazia.
            'show_ncm' => collect($items)->contains(fn ($item) => filled($item['ncm'])),
            'totals' => [
                'subtotal' => $this->formatMoney($subtotal, $currencyCode, 2),
                'freight' => $freightCosts > 0 ? $this->formatMoney($freightCosts, $currencyCode, 2) : null,
                'grand_total' => $this->formatMoney($grandTotal, $currencyCode, 2),
            ],
            'payment_term' => [
                'name' => $paymentTerm?->name,
                'description' => $paymentTerm?->description,
            ],
            'shipping_details' => $this->buildShippingDetails($shipment, $incoterm),
            'import_modality' => $this->buildImportModalityData($shipment),
            'manufacturers' => $this->buildManufacturerNames(),
        ];
    }

    private function buildInvoiceItems(Shipment $shipment, string $currencyCode, ?string $priceFormula = null, bool $useCustomPrices = true): array
    {
        return $shipment->items
            ->sortBy('sort_order')
            ->values()
            ->map(function ($item, $index) use ($currencyCode, $priceFormula, $useCustomPrices) {
                $product = $item->proformaInvoiceItem?->product;
                $piItem = $item->proformaInvoiceItem;

                $identity = $this->identity->resolve(
                    $product,
                    lineName: $item->product_name,
                    lineDescription: $piItem?->description,
                );

                $unitPrice = $this->effectiveUnitPrice($item, $priceFormula, $useCustomPrices);
                $lineTotal = $unitPrice * $item->quantity;

                return [
                    'index' => $index + 1,
                    // Linha sem produto continua imprimindo "—"; produto sem
                    // identificador continua imprimindo célula vazia.
                    'model_no' => $product ? $identity->code : '—',
                    'ncm' => self::formatNcm($identity->ncm),
                    'product_name' => $identity->name,
                    'description' => $this->formatDescription(
                        $identity->description ?: ($piItem?->specifications ?? '')
                    ),
                    'quantity' => $item->quantity,
                    'unit' => $piItem?->unit ?? 'pcs',
                    'unit_price' => $this->formatMoney($unitPrice, $currencyCode),
                    'line_total' => $this->formatMoney($lineTotal, $currencyCode, 2),
                ];
            })
            ->toArray();
    }

    /**
     * Resolve the effective unit price for an item, applying — in priority order —
     * the manual formula (if provided), then the client custom price from the
     * company_product pivot (if enabled and present), falling back to the PI
     * item's unit_price. Formula and custom_prices are mutually exclusive in
     * the UI, but this method handles both flags safely with formula winning.
     */
    private function effectiveUnitPrice($item, ?string $priceFormula, bool $useCustomPrices): int
    {
        $base = (int) $item->unit_price;

        if ($priceFormula) {
            return (int) CustomPricePdfTemplate::applyFormula($base, $priceFormula);
        }

        if ($useCustomPrices) {
            $pivot = $this->identity?->pivot($item->proformaInvoiceItem?->product);

            if ($pivot && filled($pivot->custom_price) && $pivot->custom_price > 0) {
                return (int) $pivot->custom_price;
            }
        }

        return $base;
    }

    private function buildShippingDetails(Shipment $shipment, $incoterm): array
    {
        $incotermStr = $incoterm instanceof \BackedEnum ? $incoterm->value : ($incoterm ?? '');

        return array_filter([
            // Só o incoterm: anexar o porto de DESTINO estava errado (FOB é
            // nomeado pelo porto de embarque), e o porto certo já aparece nas
            // linhas port_of_loading/port_of_destination logo abaixo.
            'delivery_term' => $incotermStr ?: null,
            'port_of_loading' => $shipment->origin_port,
            'port_of_destination' => $shipment->destination_port,
            'country_of_origin' => 'China',
        ]);
    }

    private function buildImportModalityData(Shipment $shipment): array
    {
        $modality = $shipment->import_modality ?? ImportModality::DIRECT;

        if (! $modality->requiresNotifyParty()) {
            return [
                'is_conta_e_ordem' => false,
            ];
        }

        $importerParsed = $this->parseImporterDetails(
            $shipment->company?->contracted_importer_details
        );

        $documentClient = $shipment->getDocumentClient();

        return [
            'is_conta_e_ordem' => true,
            'modality_label' => $modality->getEnglishLabel(),
            'importer' => $importerParsed,
            'notify_party' => [
                'name' => filled($documentClient->legal_name) ? $documentClient->legal_name : ($documentClient->name ?? '—'),
                'legal_name' => null,
                'address' => $documentClient->full_address ?? '—',
                'phone' => $documentClient->phone,
                'email' => $documentClient->email,
                'tax_id' => $documentClient->tax_number,
            ],
        ];
    }

    private function parseImporterDetails(?string $raw): array
    {
        if (empty($raw)) {
            return [
                'name' => 'Not configured',
                'details' => '',
            ];
        }

        $lines = preg_split('/\r?\n/', trim($raw));
        $name = array_shift($lines);
        $details = implode("\n", array_filter(array_map('trim', $lines)));

        return [
            'name' => $name,
            'details' => $details,
        ];
    }

    private function buildManufacturerNames(): array
    {
        $ids = $this->options['manufacturer_ids'] ?? [];

        if (empty($ids)) {
            return [];
        }

        return Company::whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => filled($c->legal_name) ? $c->legal_name : $c->name)
            ->toArray();
    }

    /**
     * O documento mostra a posição de 4 dígitos; o banco guarda os 8 que o
     * despachante enviou, que é o que a DI/DUIMP precisa. Formatação é do
     * documento, não do dado.
     *
     * external_ncm chega de uma planilha reimportada sem validação
     * (ClientProductsReportImporter grava o texto quase cru), então "extrair
     * os dígitos e truncar" transformava qualquer ruído em algo que parece
     * uma posição válida: "Ref 12: 8431.49.00" virava "1284", "NCM a definir
     * 2026" virava "2026" — nenhum dos dois é NCM, mas nada no documento
     * denunciava. Por isso a validação acontece ANTES de extrair dígitos:
     * se sobrar qualquer caractere que não seja dígito ou separador
     * (ponto, barra, hífen, espaço), o valor inteiro é rejeitado — não só
     * truncado. Só depois disso os dígitos são contados: 4 a 8, o mesmo
     * intervalo que ClientNcmInput já valida na entrada do formulário.
     *
     * Um valor fora do intervalo (curto demais ou comprido demais) devolve
     * null: um fragmento de posição num documento aduaneiro é pior do que
     * campo vazio.
     */
    private static function formatNcm(?string $ncm): ?string
    {
        $trimmed = trim((string) $ncm);

        if ($trimmed === '' || ! preg_match('/^[\d.\-\/\s]+$/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        return preg_match('/^\d{4,8}$/', $digits) ? substr($digits, 0, 4) : null;
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
