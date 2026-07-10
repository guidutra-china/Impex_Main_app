<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Illuminate\Support\Str;

/**
 * Expande itens com "variantes" (sub-linhas de peso/tamanho do mesmo produto, cada
 * uma com qtd/preço próprios) em itens planos, logo após a extração/edição — todo o
 * restante do pipeline (matching de fotos, resolvers, review, chat edit) só vê itens
 * planos. Cada variante vira um produto próprio no confirm, então o part_no
 * sintetizado PRECISA ser distinto por variante: part_nos repetidos são colapsados
 * em um único produto pelo dedup do import (createdByRef).
 *
 * Linhas expandidas carregam `_variant_group` (índice do item pai) para que o
 * matching de fotos compartilhe a mesma foto entre as irmãs.
 */
class ExpandDraftVariants
{
    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function expand(array $draft): array
    {
        $out = [];

        foreach (array_values($draft['itens'] ?? []) as $parentIndex => $item) {
            $variants = $item['variantes'] ?? null;
            unset($item['variantes']);

            if (! is_array($variants) || $variants === []) {
                $out[] = $item;

                continue;
            }

            foreach (array_values($variants) as $subIndex => $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $out[] = $this->variantRow($item, $variant, $parentIndex, $subIndex);
            }
        }

        $draft['itens'] = $out;

        return $draft;
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  array<string,mixed>  $variant
     * @return array<string,mixed>
     */
    private function variantRow(array $parent, array $variant, int $parentIndex, int $subIndex): array
    {
        $rotulo = trim((string) ($variant['rotulo'] ?? ''));
        $row = $parent;

        $description = trim((string) ($parent['description'] ?? ''));
        if ($rotulo !== '' && ! str_contains(mb_strtolower($description), mb_strtolower($rotulo))) {
            $description = trim($description === '' ? $rotulo : $description.' — '.$rotulo);
        }
        $row['description'] = $description;

        $row['part_no'] = $this->variantPartNo($parent, $variant, $rotulo, $subIndex);

        // Números próprios da variante substituem os do pai; ausentes herdam.
        foreach (['quantity', 'unit_price', 'line_total', 'target_price'] as $field) {
            if (isset($variant[$field]) && $variant[$field] !== '') {
                $row[$field] = $variant[$field];
            }
        }

        $row['_variant_group'] = $parentIndex;

        return $row;
    }

    /**
     * Part number único por variante: o da própria variante quando houver; senão
     * "<pai>-<ROTULO-SLUG>"; sem rótulo, sufixo posicional para nunca repetir.
     *
     * @param  array<string,mixed>  $parent
     * @param  array<string,mixed>  $variant
     */
    private function variantPartNo(array $parent, array $variant, string $rotulo, int $subIndex): ?string
    {
        $own = trim((string) ($variant['part_no'] ?? ''));
        if ($own !== '') {
            return $own;
        }

        $parentPartNo = trim((string) ($parent['part_no'] ?? ''));
        if ($parentPartNo === '') {
            return null;
        }

        $suffix = $rotulo !== '' ? strtoupper(Str::slug($rotulo)) : 'V'.($subIndex + 1);

        return $parentPartNo.'-'.$suffix;
    }
}
