<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;

/**
 * Casa a resposta extraída do fornecedor com as linhas da SQ (pela posição
 * "linha" que o modelo devolve, 1 = primeiro item em sort_order). Sem escrita,
 * sem LLM. Linha inexistente ou repetida vai para "nao_casados" — nunca
 * sobrescreve outra linha.
 */
class ResolveSupplierQuotationResponse
{
    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(SupplierQuotation $sq, array $draft): array
    {
        $items = $sq->items()->orderBy('id')->get()->values();

        $byLine = [];
        $naoCasados = [];

        foreach ((array) ($draft['itens'] ?? []) as $extracted) {
            $extracted = (array) $extracted;
            $line = (int) ($extracted['linha'] ?? 0);
            $description = trim((string) ($extracted['descricao_documento'] ?? ''));

            if ($line < 1 || $line > $items->count() || isset($byLine[$line])) {
                $naoCasados[] = [
                    'descricao' => $description,
                    'valor' => $extracted['line_total'] ?? $extracted['unit_price'] ?? null,
                ];

                continue;
            }

            $byLine[$line] = $extracted;
        }

        foreach ((array) ($draft['extras'] ?? []) as $extra) {
            $extra = (array) $extra;
            $naoCasados[] = [
                'descricao' => trim((string) ($extra['descricao'] ?? '')),
                'valor' => $extra['valor'] ?? null,
            ];
        }

        $linhas = $items->map(function ($item, int $index) use ($byLine) {
            $extracted = $byLine[$index + 1] ?? null;

            return [
                'sq_item_id' => $item->id,
                'linha' => $index + 1,
                'description' => (string) $item->description,
                'quantity' => (int) $item->quantity,
                'unit' => (string) $item->unit,
                'matched' => $extracted !== null,
                'unit_cost_minor' => $extracted !== null ? $this->unitCostMinor($extracted, (int) $item->quantity) : null,
                'moq' => isset($extracted['moq']) ? (int) $extracted['moq'] : null,
                'lead_time_days' => isset($extracted['lead_time_days']) ? (int) $extracted['lead_time_days'] : null,
                'notes' => filled($extracted['notes'] ?? null) ? (string) $extracted['notes'] : null,
                'source' => $extracted !== null ? trim((string) ($extracted['descricao_documento'] ?? '')) : null,
            ];
        })->all();

        $header = (array) ($draft['cabecalho'] ?? []);
        $currency = filled($header['currency_code'] ?? null) ? strtoupper(trim((string) $header['currency_code'])) : null;
        // RMB é como os fornecedores costumam escrever CNY.
        $currency = $currency === 'RMB' ? 'CNY' : $currency;

        $matched = count(array_filter($linhas, fn ($l) => $l['matched']));

        return [
            'cabecalho' => [
                'currency_code' => $currency,
                'supplier_reference' => filled($header['supplier_reference'] ?? null) ? (string) $header['supplier_reference'] : null,
                'valid_until' => $this->date($header['valid_until'] ?? null),
                'lead_time_days' => isset($header['lead_time_days']) ? (int) $header['lead_time_days'] : null,
                'moq' => isset($header['moq']) ? (int) $header['moq'] : null,
                'incoterm' => filled($header['incoterm'] ?? null) ? (string) $header['incoterm'] : null,
            ],
            'currency_mismatch' => $currency !== null && $currency !== strtoupper((string) $sq->currency_code),
            'linhas' => $linhas,
            'nao_casados' => $naoCasados,
            'resumo' => [
                'cotados' => $matched,
                'sem_preco' => count($linhas) - $matched,
            ],
        ];
    }

    /**
     * Preço unitário em minor units. O total da linha é o valor confiável quando
     * existe (preço por kg, por caixa...): divide pela quantidade COTADA, ou pela
     * nossa quando o documento não traz quantidade.
     *
     * @param  array<string,mixed>  $extracted
     */
    private function unitCostMinor(array $extracted, int $ourQuantity): ?int
    {
        $lineTotal = $extracted['line_total'] ?? null;
        $quantity = (float) ($extracted['quantity'] ?? 0) ?: $ourQuantity;

        if ($lineTotal !== null && $lineTotal !== '' && $quantity > 0) {
            return (int) round(Money::toMinor($lineTotal) / $quantity);
        }

        $unitPrice = $extracted['unit_price'] ?? null;

        return ($unitPrice !== null && $unitPrice !== '') ? Money::toMinor($unitPrice) : null;
    }

    private function date(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
