<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;

/**
 * Deterministically resolves an extracted draft into a preview model: matches the
 * supplier and each product (by reference_code/model_number), and converts decimal
 * prices to integer minor units. No writes, no LLM.
 *
 * Per-unit cost is derived from the line total (the document's "Amount" column)
 * when present — this is the trustworthy figure when the unit price is quoted per
 * kg or otherwise does not multiply cleanly by quantity. Non-product lines
 * (fees, discounts) are folded into the quotation notes, and the items + extras
 * are reconciled against the document total to flag mismatches.
 */
class ResolveSupplierQuotationDraft
{
    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(array $draft): array
    {
        $supplierName = trim((string) ($draft['fornecedor']['nome'] ?? ''));
        $supplier = $supplierName !== ''
            ? Company::where('name', 'like', '%'.$supplierName.'%')->first()
            : null;

        $currency = strtoupper((string) ($draft['fornecedor']['currency_code'] ?? 'USD'));
        $itens = array_map(fn (array $item) => $this->resolveItem($item), $draft['itens'] ?? []);

        $existing = count(array_filter($itens, fn ($i) => $i['status'] === 'existente'));
        $itemsMinor = array_sum(array_map(fn ($i) => $i['line_total_minor'], $itens));

        // Non-product lines (fees, discounts). Discounts come through negative.
        $extras = array_map(fn (array $e) => [
            'descricao' => (string) ($e['descricao'] ?? $e['description'] ?? ''),
            'valor_minor' => Money::toMinor($e['valor'] ?? $e['amount'] ?? 0),
        ], $draft['extras'] ?? []);
        $extrasMinor = array_sum(array_map(fn ($e) => $e['valor_minor'], $extras));

        $docTotalMinor = isset($draft['documento_total'])
            ? Money::toMinor($draft['documento_total'])
            : null;

        // Reconcile: items + extras should equal the document total (within a cent per line).
        $divergencia = false;
        if ($docTotalMinor !== null) {
            $tolerance = max(100, count($itens) * 100);
            $divergencia = abs(($itemsMinor + $extrasMinor) - $docTotalMinor) > $tolerance;
        }

        return [
            'fornecedor' => [
                'status' => $supplier ? 'existente' : 'novo',
                'company_id' => $supplier?->id,
                'nome' => $supplier?->name ?? $supplierName,
            ],
            'fornecedor_dados' => [
                'legal_name' => $draft['fornecedor']['legal_name'] ?? null,
                'tax_number' => $draft['fornecedor']['tax_number'] ?? null,
                'phone' => $draft['fornecedor']['phone'] ?? null,
                'email' => $draft['fornecedor']['email'] ?? null,
                'website' => $draft['fornecedor']['website'] ?? null,
                'address_street' => $draft['fornecedor']['address_street'] ?? null,
                'address_number' => $draft['fornecedor']['address_number'] ?? null,
                'address_complement' => $draft['fornecedor']['address_complement'] ?? null,
                'address_city' => $draft['fornecedor']['address_city'] ?? null,
                'address_state' => $draft['fornecedor']['address_state'] ?? null,
                'address_zip' => $draft['fornecedor']['address_zip'] ?? null,
                'address_country' => $draft['fornecedor']['address_country'] ?? null,
            ],
            'cabecalho' => [
                'currency_code' => $currency,
                'incoterm' => $draft['fornecedor']['incoterm'] ?? null,
                'lead_time_days' => isset($draft['fornecedor']['lead_time_days']) ? (int) $draft['fornecedor']['lead_time_days'] : null,
                'moq' => isset($draft['fornecedor']['moq']) ? (int) $draft['fornecedor']['moq'] : null,
                'valid_until' => $draft['fornecedor']['valid_until'] ?? null,
                'supplier_reference' => $draft['fornecedor']['supplier_reference'] ?? null,
                'notes' => $this->buildNotes($draft['fornecedor']['notes'] ?? null, $extras, $currency),
            ],
            'itens' => $itens,
            'resumo' => [
                'total_itens' => count($itens),
                'produtos_existentes' => $existing,
                'produtos_novos' => count($itens) - $existing,
                'produtos_sem_categoria' => count(array_filter($itens, fn ($i) => $i['category_id'] === null)),
                'total_estimado' => $currency.' '.Money::format($itemsMinor),
                'documento_total' => $docTotalMinor !== null ? $currency.' '.Money::format($docTotalMinor) : null,
                'divergencia' => $divergencia,
                'extras' => array_map(fn ($e) => [
                    'descricao' => $e['descricao'],
                    'valor' => $currency.' '.Money::format($e['valor_minor']),
                ], $extras),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function resolveItem(array $item): array
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));
        $product = $partNo !== ''
            ? Product::where('reference_code', $partNo)->orWhere('model_number', $partNo)->first()
            : null;

        $quantity = (int) ($item['quantity'] ?? 0);
        $unitCostMinor = $this->unitCostMinor($item, $quantity);
        $category = $this->matchCategory($item['categoria'] ?? null);

        return [
            'status' => $product ? 'existente' : 'novo',
            'product_id' => $product?->id,
            'part_no' => $partNo !== '' ? $partNo : null,
            'description' => (string) ($item['description'] ?? ''),
            'quantity' => $quantity,
            // Documents often omit a unit; default so the preview and the stored value match.
            'unit' => trim((string) ($item['unit'] ?? '')) ?: 'pcs',
            'unit_cost_minor' => $unitCostMinor,
            'line_total_minor' => $unitCostMinor * $quantity,
            'category_id' => $category?->id,
            'category_name' => $category?->name,
            'specifications' => $item['specifications'] ?? null,
            'moq' => isset($item['moq']) ? (int) $item['moq'] : null,
            'lead_time_days' => isset($item['lead_time_days']) ? (int) $item['lead_time_days'] : null,
            'notes' => $item['notes'] ?? null,
        ];
    }

    /**
     * Per-unit cost in minor units. Prefer deriving from the line total (the
     * document "Amount" column) when present, since the unit price may be quoted
     * per kg; fall back to the literal unit price.
     *
     * @param  array<string,mixed>  $item
     */
    private function unitCostMinor(array $item, int $quantity): int
    {
        $lineTotal = $item['line_total'] ?? null;

        if ($lineTotal !== null && $lineTotal !== '' && $quantity > 0) {
            return (int) round(Money::toMinor($lineTotal) / $quantity);
        }

        return Money::toMinor($item['unit_price'] ?? 0);
    }

    /**
     * Match the model-suggested category name to an existing category (exact,
     * case-insensitive). Never creates a category — unmatched items stay null and
     * are flagged in the preview.
     */
    private function matchCategory(?string $name): ?Category
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    /**
     * Fold non-product lines (fees, discounts) into the quotation notes so they
     * are not lost — they are not imported as product items.
     *
     * @param  list<array{descricao:string,valor_minor:int}>  $extras
     */
    private function buildNotes(?string $notes, array $extras, string $currency): ?string
    {
        if ($extras === []) {
            return $notes;
        }

        $lines = array_map(
            fn ($e) => $e['descricao'].': '.$currency.' '.Money::format($e['valor_minor']),
            $extras,
        );

        $extrasText = 'Taxas/descontos do documento: '.implode('; ', $lines);

        return $notes ? $notes."\n\n".$extrasText : $extrasText;
    }
}
