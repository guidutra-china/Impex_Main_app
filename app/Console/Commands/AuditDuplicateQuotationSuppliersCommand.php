<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only production health-check for the duplicate QuotationItemSupplier bug.
 *
 * O bug: ao criar uma Quotation a partir de uma Inquiry, o mesmo fornecedor
 * pode ter cotado o MESMO produto em 2+ Supplier Quotations. O loop de gravação
 * tentava inserir (quotation_item_id, company_id) repetido, violando o índice
 * único quotation_item_suppliers_quotation_item_id_company_id_unique.
 *
 * Este comando NÃO altera nada. Reporta dois sinais:
 *
 *   1. Linhas duplicadas já persistidas em quotation_item_suppliers. Em teoria
 *      são impossíveis (o índice único bloqueia), mas checamos de forma defensiva
 *      caso o índice tenha sido criado depois dos dados, ou algum path o ignore.
 *
 *   2. Inquiries com o "formato" que dispara o bug — mesmo fornecedor cotando o
 *      mesmo produto em 2+ SQs da inquiry. Eram as cotações BLOQUEADAS antes do
 *      fix; com o fix, criam normalmente (mantendo a cotação mais barata).
 *
 * Exit code != 0 quando encontra qualquer um dos dois, para uso como gate
 * pós-deploy / monitoramento.
 */
class AuditDuplicateQuotationSuppliersCommand extends Command
{
    protected $signature = 'quotations:audit-duplicate-suppliers
                            {--inquiry= : Limita a uma inquiry (ex.: INQ-2026-00012 ou o id numérico)}';

    protected $description = 'Auditoria read-only: detecta linhas duplicadas em quotation_item_suppliers e inquiries onde o mesmo fornecedor cotou o mesmo produto em 2+ SQs (formato que disparava o erro de constraint). Não altera dados; exit != 0 se encontrar.';

    public function handle(): int
    {
        $inquiryFilter = $this->option('inquiry');

        $duplicateRows = $this->findDuplicateRows();
        $affectedShapes = $this->findAffectedShapes($inquiryFilter);

        $hasFindings = $duplicateRows->isNotEmpty() || $affectedShapes->isNotEmpty();

        // 1. Linhas duplicadas reais (não deveriam existir por causa do índice único).
        if ($duplicateRows->isEmpty()) {
            $this->info('OK: nenhuma linha duplicada em quotation_item_suppliers (índice único íntegro).');
        } else {
            $this->error($duplicateRows->count().' par(es) (quotation_item_id, company_id) duplicado(s) em quotation_item_suppliers:');
            $this->table(
                ['quotation_item_id', 'company_id', 'qtd linhas'],
                $duplicateRows->map(fn ($r) => [
                    (string) $r->quotation_item_id,
                    (string) $r->company_id,
                    (string) $r->total,
                ])->all(),
            );
            $this->warn('Investigue manualmente — mantenha a linha de menor unit_cost e remova as demais.');
            $this->newLine();
        }

        // 2. Inquiries que disparariam o bug (mesmo fornecedor, mesmo produto, 2+ SQs).
        if ($affectedShapes->isEmpty()) {
            $this->info('OK: nenhuma inquiry com fornecedor repetido no mesmo produto (nada bloqueado pelo bug).');
        } else {
            $this->newLine();
            $this->warn($affectedShapes->count().' combinação(ões) inquiry/produto/fornecedor com 2+ linhas de cotação — eram cotações bloqueadas; com o fix criam mantendo a cotação mais barata:');
            $this->table(
                ['Inquiry', 'Produto', 'Fornecedor', 'Linhas', 'SQs'],
                $affectedShapes->map(fn ($r) => [
                    $r->inquiry ?? ('#'.$r->inquiry_id),
                    $r->product ?? ('#'.$r->product_id),
                    $r->supplier ?? ('#'.$r->company_id),
                    (string) $r->item_count,
                    (string) $r->sq_count,
                ])->all(),
            );
            $this->line('Ação: reabra/recrie a Quotation dessas inquiries — agora funciona sem erro.');
        }

        return $hasFindings ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pares (quotation_item_id, company_id) que aparecem mais de uma vez.
     */
    private function findDuplicateRows(): \Illuminate\Support\Collection
    {
        return DB::table('quotation_item_suppliers')
            ->select('quotation_item_id', 'company_id', DB::raw('COUNT(*) as total'))
            ->groupBy('quotation_item_id', 'company_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Inquiry + produto onde o mesmo fornecedor (company_id) cotou em 2+ linhas.
     * Espelha o data flow de CreateOrUpdateQuotationFromInquiryAction::syncItems
     * (unit_cost > 0; itens de SQ agrupados por produto), que era onde o bug nascia.
     *
     * Conta LINHAS de cotação (COUNT(*)), não SQs distintas: não há índice único
     * em supplier_quotation_items(supplier_quotation_id, product_id), então o mesmo
     * fornecedor pode duplicar o produto dentro de UMA SQ — e isso também disparava
     * o erro. sq_count é só informativo (1 = duplicata na mesma SQ; 2+ = entre SQs).
     */
    private function findAffectedShapes(?string $inquiryFilter): \Illuminate\Support\Collection
    {
        $query = DB::table('supplier_quotation_items as sqi')
            ->join('supplier_quotations as sq', 'sq.id', '=', 'sqi.supplier_quotation_id')
            ->join('inquiries as i', 'i.id', '=', 'sq.inquiry_id')
            ->leftJoin('products as p', 'p.id', '=', 'sqi.product_id')
            ->leftJoin('companies as c', 'c.id', '=', 'sq.company_id')
            ->whereNull('sq.deleted_at')
            ->where('sqi.unit_cost', '>', 0)
            ->selectRaw('sq.inquiry_id, sqi.product_id, sq.company_id, i.reference as inquiry, p.name as product, c.name as supplier, COUNT(*) as item_count, COUNT(DISTINCT sq.id) as sq_count')
            ->groupBy('sq.inquiry_id', 'sqi.product_id', 'sq.company_id', 'i.reference', 'p.name', 'c.name')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('item_count');

        if ($inquiryFilter !== null) {
            $query->where(function ($q) use ($inquiryFilter) {
                $q->where('i.reference', $inquiryFilter);
                if (is_numeric($inquiryFilter)) {
                    $q->orWhere('i.id', (int) $inquiryFilter);
                }
            });
        }

        return $query->get();
    }
}
