<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Enums\ImportModality;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Services\PackingProgressService;
use Illuminate\Support\Collection;

class PackingListPdfTemplate extends AbstractPdfTemplate
{
    private ?int $clientCompanyId = null;

    private array $clientPivotCache = [];

    public function getView(): string
    {
        return 'pdf.packing-list';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('packing_list');
    }

    public function getDocumentType(): string
    {
        return 'packing_list_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return 'PL-'.$reference.'-v'.$this->getNextVersion().'.pdf';
    }

    public function getOrientation(): string
    {
        return 'landscape';
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'companyBranch',
            'items.proformaInvoiceItem.product.companies',
            'items.proformaInvoiceItem.proformaInvoice',
            'cartons.contents.shipmentItem.proformaInvoiceItem.product.companies',
            'cartons.shipmentContainer',
            'cartons.pallet.container',
        ]);

        $this->clientCompanyId = $shipment->company_id;
        $this->warmPivotCache($shipment);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $piReferences = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');

        $containerGroups = $this->buildContainerGroupsFromCartons($shipment);
        $totals = $this->computeShipmentTotals($shipment);

        $documentDate = $shipment->issue_date ?? $shipment->etd ?? $shipment->created_at ?? now();

        $documentClient = $shipment->getDocumentClient();

        return [
            'shipment' => [
                'reference' => $shipment->reference,
                'origin_port' => $shipment->origin_port,
                'destination_port' => $shipment->destination_port,
                'date' => $this->formatDate($documentDate),
                'etd' => $this->formatDate($shipment->etd),
                'bl_number' => $shipment->bl_number,
                'vessel_name' => $shipment->vessel_name,
                'currency_code' => $currencyCode,
                'pi_references' => $piReferences,
            ],
            'client' => [
                'name' => filled($documentClient->legal_name) ? $documentClient->legal_name : ($documentClient->name ?? '—'),
                'legal_name' => null,
                'address' => $documentClient->full_address ?? '—',
                'phone' => $documentClient->phone,
                'email' => $documentClient->email,
                'tax_id' => $documentClient->tax_number,
            ],
            'container_groups' => $containerGroups,
            'has_multiple_containers' => count($containerGroups) > 1,
            'totals' => $totals,
            'import_modality' => $this->buildImportModalityData($shipment),
        ];
    }

    /**
     * @return array<int, array{container_number: ?string, lines: array, totals: array}>
     */
    private function buildContainerGroupsFromCartons(Shipment $shipment): array
    {
        $cartons = $shipment->cartons
            ->sortBy([['sort_order', 'asc'], ['label', 'asc']])
            ->values();

        $grouped = $cartons->groupBy(function (Carton $c) {
            $effective = $c->getEffectiveContainer();
            if ($effective) {
                return $effective->container_number ?: $effective->label;
            }

            return $c->container_number ?: '__none__';
        });

        $containerGroups = [];

        foreach ($grouped as $containerNumber => $containerCartons) {
            $lines = $this->buildLinesFromCartons($containerCartons);

            $containerGroups[] = [
                'container_number' => $containerNumber === '__none__' ? null : $containerNumber,
                'lines' => $lines,
                'totals' => $this->computeCartonSubtotals($containerCartons),
            ];
        }

        return $containerGroups;
    }

    /**
     * @param  Collection<int, Carton>  $cartons
     */
    private function buildLinesFromCartons(Collection $cartons): array
    {
        $lines = [];

        // Separate cartons into groupable (single-product, identical specs) and ungroupable
        $groups = [];
        $ungroupable = [];

        foreach ($cartons as $carton) {
            $contents = $carton->contents->sortBy('sort_order')->values();

            if ($contents->isEmpty()) {
                $ungroupable[] = ['carton' => $carton, 'contents' => $contents];

                continue;
            }

            // Only group cartons with exactly 1 content (single-product boxes)
            if ($contents->count() === 1) {
                $signature = $this->buildCartonSignature($carton, $contents->first());
                $groups[$signature][] = ['carton' => $carton, 'content' => $contents->first()];
            } else {
                $ungroupable[] = ['carton' => $carton, 'contents' => $contents];
            }
        }

        // Build consolidated lines for grouped cartons (preserving original sort order)
        // We track the first carton's sort_order to interleave groups with ungroupable cartons
        $sortableLines = [];

        foreach ($groups as $group) {
            $firstCarton = $group[0]['carton'];
            $sortKey = $firstCarton->sort_order ?? 0;

            if (count($group) === 1) {
                // Single carton in group — render normally
                $sortableLines[] = [
                    'sort_key' => $sortKey,
                    'lines' => [$this->buildContentLine($firstCarton, $group[0]['content'], true)],
                ];
            } else {
                // Multiple identical cartons — consolidate into one line
                $sortableLines[] = [
                    'sort_key' => $sortKey,
                    'lines' => $this->buildGroupedLines($group),
                ];
            }
        }

        foreach ($ungroupable as $item) {
            $carton = $item['carton'];
            $contents = $item['contents'];
            $sortKey = $carton->sort_order ?? 0;
            $itemLines = [];

            if ($contents->isEmpty()) {
                $itemLines[] = $this->buildEmptyCartonLine($carton);
            } else {
                foreach ($contents as $index => $content) {
                    $isFirst = $index === 0;
                    $itemLines[] = $this->buildContentLine($carton, $content, $isFirst);
                }
            }

            $sortableLines[] = [
                'sort_key' => $sortKey,
                'lines' => $itemLines,
            ];
        }

        // Sort by original carton order, then flatten
        usort($sortableLines, fn ($a, $b) => $a['sort_key'] <=> $b['sort_key']);

        foreach ($sortableLines as $entry) {
            foreach ($entry['lines'] as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Build a signature string to identify cartons that can be grouped together.
     * Cartons with the same signature have identical product, qty, dimensions and weight.
     */
    private function buildCartonSignature(Carton $carton, $content): string
    {
        return implode('|', [
            $content->shipment_item_id,
            (int) $content->pieces,
            $content->part_label ?? '',
            (string) ($carton->gross_weight ?? ''),
            (string) ($carton->net_weight ?? ''),
            (string) ($carton->length ?? ''),
            (string) ($carton->width ?? ''),
            (string) ($carton->height ?? ''),
            (string) ($carton->packaging_type?->value ?? ''),
            $this->resolvePalletLabel($carton) ?? '',
        ]);
    }

    /**
     * Build a consolidated line for a group of identical cartons.
     *
     * @param  array<int, array{carton: Carton, content: mixed}>  $group
     */
    private function buildGroupedLines(array $group): array
    {
        $firstCarton = $group[0]['carton'];
        $lastCarton = $group[count($group) - 1]['carton'];
        $content = $group[0]['content'];
        $count = count($group);

        $shipmentItem = $content->shipmentItem;
        $product = $shipmentItem?->proformaInvoiceItem?->product;
        $pivot = $this->getClientPivot($product);

        $productName = $pivot?->external_name ?: ($shipmentItem?->product_name ?? '—');
        if (filled($content->part_label)) {
            $productName .= ' — '.$content->part_label;
        }

        // Build package range label (e.g. "CTN-001 ~ CTN-050")
        $packageRange = $firstCarton->label.' ~ '.$lastCarton->label;

        // Sum totals across all cartons in the group
        $totalEquipmentQty = (int) $content->pieces * $count;
        $totalGrossWeight = (float) $firstCarton->gross_weight * $count;
        $totalNetWeight = (float) $firstCarton->net_weight * $count;
        $totalVolume = (float) $firstCarton->volume * $count;

        return [
            [
                'package_no' => $packageRange,
                'pallet' => $this->resolvePalletLabel($firstCarton),
                'model_no' => $pivot?->external_code ?: ($product?->model_number ?: ($product?->sku ?? '')),
                'product_name' => $productName,
                'description' => $shipmentItem?->notes,
                'unit' => $shipmentItem?->unit ?? 'pcs',
                'equipment_qty' => $totalEquipmentQty,
                'package_qty' => $count,
                'net_weight' => $totalNetWeight > 0 ? number_format($totalNetWeight, 1) : '',
                'gross_weight' => $totalGrossWeight > 0 ? number_format($totalGrossWeight, 1) : '',
                'dimensions' => $this->formatCartonDimensions($firstCarton),
                'volume' => $totalVolume > 0 ? number_format($totalVolume, 2) : '',
                'is_sub_item' => false,
            ],
        ];
    }

    private function buildEmptyCartonLine(Carton $carton): array
    {
        return [
            'package_no' => $carton->label,
            'pallet' => $this->resolvePalletLabel($carton),
            'model_no' => '',
            'product_name' => '—',
            'description' => null,
            'unit' => '',
            'equipment_qty' => '',
            'package_qty' => 1,
            'net_weight' => $carton->net_weight ? number_format((float) $carton->net_weight, 1) : '',
            'gross_weight' => $carton->gross_weight ? number_format((float) $carton->gross_weight, 1) : '',
            'dimensions' => $this->formatCartonDimensions($carton),
            'volume' => $carton->volume ? number_format((float) $carton->volume, 2) : '',
            'is_sub_item' => false,
        ];
    }

    private function buildContentLine(Carton $carton, $content, bool $isFirst): array
    {
        $shipmentItem = $content->shipmentItem;
        $product = $shipmentItem?->proformaInvoiceItem?->product;
        $pivot = $this->getClientPivot($product);

        $productName = $pivot?->external_name ?: ($shipmentItem?->product_name ?? '—');
        if (filled($content->part_label)) {
            $productName .= ' — '.$content->part_label;
        }

        if ($isFirst) {
            return [
                'package_no' => $carton->label,
                'pallet' => $this->resolvePalletLabel($carton),
                'model_no' => $pivot?->external_code ?: ($product?->model_number ?: ($product?->sku ?? '')),
                'product_name' => $productName,
                'description' => $shipmentItem?->notes,
                'unit' => $shipmentItem?->unit ?? 'pcs',
                'equipment_qty' => (int) $content->pieces,
                'package_qty' => 1,
                'net_weight' => $this->formatContentNetWeight($carton, $content, true),
                'gross_weight' => $this->formatContentGrossWeight($carton, $content, true),
                'dimensions' => $this->formatCartonDimensions($carton),
                'volume' => $carton->volume ? number_format((float) $carton->volume, 2) : '',
                'is_sub_item' => false,
            ];
        }

        return [
            'package_no' => '',
            'pallet' => null,
            'model_no' => $pivot?->external_code ?: ($product?->model_number ?: ($product?->sku ?? '')),
            'product_name' => $productName,
            'description' => $shipmentItem?->notes,
            'unit' => $shipmentItem?->unit ?? 'pcs',
            'equipment_qty' => (int) $content->pieces,
            'package_qty' => '',
            'net_weight' => $this->formatContentNetWeight($carton, $content, false),
            'gross_weight' => $this->formatContentGrossWeight($carton, $content, false),
            'dimensions' => '',
            'volume' => '',
            'is_sub_item' => true,
        ];
    }

    private function formatContentGrossWeight(Carton $carton, $content, bool $isFirst): string
    {
        // Prefer weight_share when set; otherwise only show carton gross on first line.
        if ($content->weight_share !== null) {
            return number_format((float) $content->weight_share, 1);
        }

        if ($isFirst && $carton->gross_weight !== null) {
            return number_format((float) $carton->gross_weight, 1);
        }

        return '';
    }

    private function formatContentNetWeight(Carton $carton, $content, bool $isFirst): string
    {
        // Net weight follows the same pattern — no per-content net_weight field,
        // so only the first line carries the carton's net weight.
        if ($isFirst && $carton->net_weight !== null) {
            return number_format((float) $carton->net_weight, 1);
        }

        return '';
    }

    /**
     * Resolve the pallet label for a carton — prefers the Pallet relation
     * (new hierarchy), falls back to the legacy pallet_number integer column.
     */
    private function resolvePalletLabel(Carton $carton): ?string
    {
        if ($carton->shipment_pallet_id && $carton->pallet) {
            return $carton->pallet->label;
        }

        if ($carton->pallet_number) {
            return 'PLT-'.str_pad((string) $carton->pallet_number, 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    private function formatCartonDimensions(Carton $carton): string
    {
        if (! $carton->length && ! $carton->width && ! $carton->height) {
            return '';
        }

        $l = $carton->length ? number_format((float) $carton->length, 0) : '—';
        $w = $carton->width ? number_format((float) $carton->width, 0) : '—';
        $h = $carton->height ? number_format((float) $carton->height, 0) : '—';

        return "{$l} × {$w} × {$h}";
    }

    /**
     * Grand totals — uses PackingProgressService to dedupe multi-box sets for equipment_qty.
     */
    private function computeShipmentTotals(Shipment $shipment): array
    {
        $cartons = $shipment->cartons;

        $totalPackages = $cartons->count();
        $totalGross = (float) $cartons->sum(fn ($c) => (float) $c->gross_weight);
        $totalNet = (float) $cartons->sum(fn ($c) => (float) $c->net_weight);
        $totalVolume = (float) $cartons->sum(fn ($c) => (float) $c->volume);

        $progress = app(PackingProgressService::class)->forShipment($shipment);
        $totalEquipmentQty = (int) $progress->sum(fn ($p) => $p->packedComplete);

        return [
            'total_packages' => $totalPackages,
            'total_gross_weight' => $totalGross,
            'total_net_weight' => $totalNet,
            'total_volume' => $totalVolume,
            'total_equipment_qty' => $totalEquipmentQty,
            'packages' => $totalPackages,
            'equipment_qty' => $totalEquipmentQty,
            'gross_weight' => $totalGross,
            'net_weight' => $totalNet,
            'volume' => $totalVolume,
        ];
    }

    /**
     * Per-container subtotals — no dedupe needed because the grand total handles it.
     * Sums pieces directly for equipment_qty display.
     *
     * @param  Collection<int, Carton>  $cartons
     */
    private function computeCartonSubtotals(Collection $cartons): array
    {
        $totalPackages = $cartons->count();
        $totalGross = (float) $cartons->sum(fn ($c) => (float) $c->gross_weight);
        $totalNet = (float) $cartons->sum(fn ($c) => (float) $c->net_weight);
        $totalVolume = (float) $cartons->sum(fn ($c) => (float) $c->volume);

        $totalEquipmentQty = (int) $cartons
            ->flatMap(fn (Carton $c) => $c->contents)
            ->sum(fn ($content) => (int) $content->pieces);

        return [
            'total_packages' => $totalPackages,
            'total_gross_weight' => $totalGross,
            'total_net_weight' => $totalNet,
            'total_volume' => $totalVolume,
            'total_equipment_qty' => $totalEquipmentQty,
            'packages' => $totalPackages,
            'equipment_qty' => $totalEquipmentQty,
            'gross_weight' => $totalGross,
            'net_weight' => $totalNet,
            'volume' => $totalVolume,
        ];
    }

    private function warmPivotCache(Shipment $shipment): void
    {
        if (! $this->clientCompanyId) {
            return;
        }

        foreach ($shipment->items as $item) {
            $this->cachePivotForProduct($item->proformaInvoiceItem?->product);
        }

        foreach ($shipment->cartons as $carton) {
            foreach ($carton->contents as $content) {
                $this->cachePivotForProduct($content->shipmentItem?->proformaInvoiceItem?->product);
            }
        }
    }

    private function cachePivotForProduct(?Product $product): void
    {
        if (! $product || isset($this->clientPivotCache[$product->id])) {
            return;
        }

        $clientPivot = $product->companies
            ->where('pivot.company_id', $this->clientCompanyId)
            ->where('pivot.role', 'client')
            ->first();

        if ($clientPivot) {
            $this->clientPivotCache[$product->id] = $clientPivot->pivot;
        }
    }

    private function getClientPivot(?Product $product)
    {
        if (! $product) {
            return null;
        }

        return $this->clientPivotCache[$product->id] ?? null;
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

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
