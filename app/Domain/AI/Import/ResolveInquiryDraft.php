<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;

/**
 * Deterministically resolves an extracted inquiry draft into a preview model:
 * matches the client company by name and each product by reference_code/
 * model_number (match-only — inquiry items never create products; unmatched
 * items keep product_id null with the description, mirroring the deterministic
 * Excel import). No writes, no LLM.
 */
class ResolveInquiryDraft
{
    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(array $draft): array
    {
        $clientName = trim((string) ($draft['cliente']['nome'] ?? ''));
        $client = $clientName !== ''
            ? Company::where('name', 'like', '%'.$clientName.'%')->first()
            : null;

        $currency = strtoupper((string) ($draft['cliente']['currency_code'] ?? 'USD'));
        $itens = array_map(fn (array $item) => $this->resolveItem($item), $draft['itens'] ?? []);

        $matched = count(array_filter($itens, fn ($i) => $i['status'] === 'existente'));
        $totalMinor = array_sum(array_map(
            fn ($i) => ($i['target_price_minor'] ?? 0) * $i['quantity'],
            $itens,
        ));

        return [
            'cliente' => [
                'status' => $client ? 'existente' : 'novo',
                'company_id' => $client?->id,
                'nome' => $client?->name ?? $clientName,
                'contato' => $draft['cliente']['contato'] ?? null,
            ],
            'cabecalho' => [
                'currency_code' => $currency,
                'deadline' => $draft['cliente']['deadline'] ?? null,
                'notes' => $draft['cliente']['notes'] ?? null,
            ],
            'itens' => $itens,
            'resumo' => [
                'total_itens' => count($itens),
                'produtos_casados' => $matched,
                'produtos_sem_match' => count($itens) - $matched,
                // Zero stays hidden ("no prices" and "all zero" are indistinguishable), but any non-zero total — even negative — must surface for review.
                'total_estimado' => $totalMinor !== 0 ? $currency.' '.Money::format($totalMinor) : null,
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

        $targetPrice = $item['target_price'] ?? null;

        return [
            // 'existente' = matched an existing product; 'novo' = no match (imports with product_id null).
            'status' => $product ? 'existente' : 'novo',
            'product_id' => $product?->id,
            'part_no' => $partNo !== '' ? $partNo : null,
            'description' => (string) ($item['description'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit' => trim((string) ($item['unit'] ?? '')) ?: 'pcs',
            'target_price_minor' => ($targetPrice !== null && $targetPrice !== '') ? Money::toMinor($targetPrice) : null,
            'specifications' => $item['specifications'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }
}
