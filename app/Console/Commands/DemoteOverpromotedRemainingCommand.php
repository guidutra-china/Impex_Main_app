<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoteOverpromotedRemainingCommand extends Command
{
    protected $signature = 'financial:demote-overpromoted-remaining
        {--dry-run : Apenas lista o que seria alterado, sem gravar}';

    protected $description = 'Detecta itens [remaining] que foram promovidos indevidamente a ship-specific quando o shipment já tinha a parcela coberta (par duplicado no mesmo shipment+stage, com alocação acima do valor). Reverte o item para [remaining], reabsorve o valor do [remaining] vazio criado na regeneração e remove esse duplicado.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Pares ship-specific duplicados: mesmo payable+stage+shipment com 2+ linhas.
        $pairs = PaymentScheduleItem::query()
            ->whereIn('payable_type', [ProformaInvoice::class, PurchaseOrder::class])
            ->whereNotNull('shipment_id')
            ->whereNotNull('payment_term_stage_id')
            ->whereNull('source_type')
            ->select('payable_type', 'payable_id', 'payment_term_stage_id', 'shipment_id')
            ->groupBy('payable_type', 'payable_id', 'payment_term_stage_id', 'shipment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($pairs->isEmpty()) {
            $this->info('Nenhum par ship-specific duplicado encontrado.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $skipped = 0;

        foreach ($pairs as $pair) {
            $items = PaymentScheduleItem::query()
                ->where('payable_type', $pair->payable_type)
                ->where('payable_id', $pair->payable_id)
                ->where('payment_term_stage_id', $pair->payment_term_stage_id)
                ->where('shipment_id', $pair->shipment_id)
                ->whereNull('source_type')
                ->withSum('allocations as allocated_total', 'allocated_amount')
                ->get();

            // O item promovido indevidamente é o que carrega alocação ACIMA do
            // próprio valor (clampado na promoção). O irmão legítimo fica intacto.
            $overAllocated = $items->filter(
                fn ($i) => (int) ($i->allocated_total ?? 0) > (int) $i->amount
            );

            if ($overAllocated->count() !== 1 || $items->count() !== 2) {
                $this->warn(sprintf(
                    'SKIP par %s#%d stage=%d shipment=%d — %d linhas, %d over-allocated (esperado 2/1); revisar manualmente.',
                    class_basename($pair->payable_type),
                    $pair->payable_id,
                    $pair->payment_term_stage_id,
                    $pair->shipment_id,
                    $items->count(),
                    $overAllocated->count(),
                ));
                $skipped++;

                continue;
            }

            /** @var PaymentScheduleItem $demote */
            $demote = $overAllocated->first();

            // O [remaining] vazio criado pela mesma regeneração tem o valor e o
            // label corretos do saldo não embarcado — reabsorvemos e removemos.
            $emptyRemaining = PaymentScheduleItem::query()
                ->where('payable_type', $pair->payable_type)
                ->where('payable_id', $pair->payable_id)
                ->where('payment_term_stage_id', $pair->payment_term_stage_id)
                ->whereNull('shipment_id')
                ->whereNull('source_type')
                ->where('label', 'LIKE', '%[remaining]%')
                ->whereDoesntHave('allocations')
                ->first();

            if (! $emptyRemaining) {
                $this->warn(sprintf(
                    'SKIP item #%d — nenhum [remaining] vazio para reabsorver o valor; revisar manualmente.',
                    $demote->id,
                ));
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                'DEMOTE item #%d "%s" (amount %d, alocado %d) -> [remaining] amount %d; apaga #%d.',
                $demote->id,
                $demote->label,
                $demote->amount,
                (int) $demote->allocated_total,
                $emptyRemaining->amount,
                $emptyRemaining->id,
            ));

            if ($dryRun) {
                $fixed++;

                continue;
            }

            DB::transaction(function () use ($demote, $emptyRemaining) {
                $demote->update([
                    'shipment_id' => null,
                    'label' => $emptyRemaining->label,
                    'amount' => $emptyRemaining->amount,
                    'due_date' => null,
                    'is_blocking' => false,
                ]);

                $emptyRemaining->delete();

                $demote->refresh();
                $demote->recalculateStatus();
            });

            $fixed++;
        }

        $this->info(sprintf(
            '%s%d item(s) revertido(s), %d pulado(s).',
            $dryRun ? '[dry-run] ' : '',
            $fixed,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
