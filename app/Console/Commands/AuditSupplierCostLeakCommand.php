<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;

/**
 * Pre-deploy audit for the [supplier-payable] tag restriction: lists open,
 * untagged schedule items sourced from costs with supplier_company_id filled.
 * These rows used to leak into the AP worklist via the loose predicate and
 * will no longer appear there — review each one before deploying.
 */
class AuditSupplierCostLeakCommand extends Command
{
    protected $signature = 'financial:audit-supplier-cost-leak';

    protected $description = 'Lista PSIs de custos com supplier_company_id que saem do worklist de AP após a restrição por tag';

    public function handle(): int
    {
        $costIds = AdditionalCost::query()
            ->whereNotNull('supplier_company_id')
            ->pluck('id');

        $rows = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereIn('status', ['pending', 'due', 'overdue'])
            ->where('source_type', AdditionalCost::class)
            ->whereIn('source_id', $costIds)
            ->withoutSideTags()
            ->whereNotIn('payable_type', [PurchaseOrder::class, DebitNote::class])
            ->with(['payable', 'source'])
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nenhuma linha afetada — nada saía do AP pelo predicado frouxo.');

            return self::SUCCESS;
        }

        $this->warn("{$rows->count()} linha(s) deixarão de aparecer no worklist de AP:");
        $this->table(
            ['PSI', 'Label', 'Payable', 'Custo', 'Billable to', 'Valor (minor)'],
            $rows->map(fn (PaymentScheduleItem $item) => [
                $item->id,
                $item->label,
                class_basename($item->payable_type).'#'.$item->payable_id,
                $item->source_id,
                $item->source?->billable_to?->value ?? '—',
                $item->amount,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
