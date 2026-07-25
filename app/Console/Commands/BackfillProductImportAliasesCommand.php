<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Import\UpsertProductImportAliasAction;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Povoa product_import_aliases a partir do histórico confirmado: itens de SQ
 * (empresa = fornecedor da SQ) e de Inquiry (empresa = cliente) que já têm
 * product_id vinculado.
 *
 * Itens de inquiry espelhados do fluxo combinado Inquiry+SQ (referenciados por
 * supplier_quotation_items.inquiry_item_id) são excluídos do lado da inquiry —
 * a descrição nesse caso é fraseado do fornecedor, não do cliente, e aprendê-la
 * como alias do cliente contaminaria o dicionário dele.
 *
 * Processa em ordem cronológica; o upsert faz o item mais recente vencer em
 * caso de descrição duplicada. Dry-run por default; --apply grava.
 */
class BackfillProductImportAliasesCommand extends Command
{
    protected $signature = 'imports:backfill-product-aliases {--apply : Persiste (senão, dry-run)}';

    protected $description = 'Backfill de aliases de produto a partir de itens de SQ/Inquiry já vinculados a produtos';

    public function handle(UpsertProductImportAliasAction $upserter): int
    {
        $apply = (bool) $this->option('apply');
        $written = 0;
        $skipped = 0;

        $process = function (int $companyId, ?int $productId, ?string $description) use ($apply, $upserter, &$written, &$skipped): void {
            if ($productId === null || blank($description)) {
                $skipped++;

                return;
            }

            if (! $apply) {
                $written++;

                return;
            }

            $result = $upserter->execute($companyId, $productId, $description, 'backfill');
            $result !== null ? $written++ : $skipped++;
        };

        SupplierQuotationItem::query()
            ->whereNotNull('product_id')
            ->join('supplier_quotations', 'supplier_quotations.id', '=', 'supplier_quotation_items.supplier_quotation_id')
            ->orderBy('supplier_quotation_items.created_at')
            ->orderBy('supplier_quotation_items.id')
            ->get(['supplier_quotation_items.*', 'supplier_quotations.company_id as sq_company_id'])
            ->each(fn ($item) => $process((int) $item->sq_company_id, $item->product_id, $item->description));

        InquiryItem::query()
            ->whereNotNull('product_id')
            // Espelhos do fluxo combinado Inquiry+SQ: descrição é do fornecedor, não do cliente.
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('supplier_quotation_items')
                    ->whereColumn('supplier_quotation_items.inquiry_item_id', 'inquiry_items.id');
            })
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_items.inquiry_id')
            ->orderBy('inquiry_items.created_at')
            ->orderBy('inquiry_items.id')
            ->get(['inquiry_items.*', 'inquiries.company_id as inq_company_id'])
            ->each(fn ($item) => $process((int) $item->inq_company_id, $item->product_id, $item->description));

        $this->info(sprintf('%s: %d aliases processados, %d ignorados.', $apply ? 'Gravado' : 'Dry-run', $written, $skipped));

        if (! $apply) {
            $this->warn('Dry-run: nada gravado. Use --apply para persistir.');
        }

        return self::SUCCESS;
    }
}
