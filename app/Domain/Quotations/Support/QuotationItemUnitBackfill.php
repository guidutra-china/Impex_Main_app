<?php

namespace App\Domain\Quotations\Support;

use Illuminate\Support\Facades\DB;

/**
 * Preenche quotation_items.unit a partir do que já existe no fluxo:
 * item da SQ vinculada > item do inquiry com o mesmo produto > mantém.
 *
 * Só sobrescreve quando a fonte tem valor; linhas sem fonte ficam como
 * estão. Idempotente — rodar duas vezes dá o mesmo resultado.
 *
 * SQL sem JOIN no UPDATE de propósito: a suíte roda em SQLite e a
 * produção em MySQL, e subconsulta correlacionada é o que os dois aceitam.
 */
class QuotationItemUnitBackfill
{
    /** @return int linhas alteradas (soma dos dois passos) */
    public static function run(): int
    {
        // 1) Inquiry — base mais ampla, aplicada primeiro.
        $fromInquiry = DB::update(<<<'SQL'
            UPDATE quotation_items
            SET unit = (
                SELECT ii.unit
                FROM inquiry_items ii
                JOIN quotations q ON q.inquiry_id = ii.inquiry_id
                WHERE q.id = quotation_items.quotation_id
                  AND ii.product_id = quotation_items.product_id
                  AND ii.unit IS NOT NULL AND ii.unit <> ''
                ORDER BY ii.id
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1
                FROM inquiry_items ii
                JOIN quotations q ON q.inquiry_id = ii.inquiry_id
                WHERE q.id = quotation_items.quotation_id
                  AND ii.product_id = quotation_items.product_id
                  AND ii.unit IS NOT NULL AND ii.unit <> ''
            )
        SQL);

        // 2) SQ vinculada — mais específica, sobrescreve o passo 1.
        $fromSq = DB::update(<<<'SQL'
            UPDATE quotation_items
            SET unit = (
                SELECT sqi.unit
                FROM supplier_quotation_items sqi
                WHERE sqi.id = quotation_items.supplier_quotation_item_id
                  AND sqi.unit IS NOT NULL AND sqi.unit <> ''
            )
            WHERE EXISTS (
                SELECT 1
                FROM supplier_quotation_items sqi
                WHERE sqi.id = quotation_items.supplier_quotation_item_id
                  AND sqi.unit IS NOT NULL AND sqi.unit <> ''
            )
        SQL);

        return $fromInquiry + $fromSq;
    }
}
