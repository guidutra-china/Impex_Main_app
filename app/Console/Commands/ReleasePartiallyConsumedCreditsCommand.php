<?php

namespace App\Console\Commands;

use App\Domain\Financial\Actions\ReconcileSettlementStateAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Console\Command;

class ReleasePartiallyConsumedCreditsCommand extends Command
{
    protected $signature = 'financial:release-partially-consumed-credits
        {--dry-run : Apenas lista o que seria alterado, sem gravar}';

    protected $description = 'Libera itens de crédito (Credit Notes) travados em PAID por consumo parcial: antes do fix de 2026-07-23, qualquer aplicação parcial marcava o item inteiro como consumido e o saldo sumia da lista de créditos disponíveis. Re-executa o ReconcileSettlementStateAction sobre uma alocação de cada item afetado.';

    public function handle(ReconcileSettlementStateAction $reconcile): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = PaymentScheduleItem::query()
            ->where('is_credit', true)
            ->where('source_type', CreditNoteLineItem::class)
            ->where('status', PaymentScheduleStatus::PAID->value)
            ->get()
            ->filter(fn (PaymentScheduleItem $item) => $item->credit_consumed_amount > 0
                && $item->credit_remaining_amount > CreditNote::SETTLEMENT_TOLERANCE);

        if ($candidates->isEmpty()) {
            $this->info('Nenhum item de crédito parcialmente consumido travado em PAID.');

            return self::SUCCESS;
        }

        $released = 0;
        $skipped = 0;

        foreach ($candidates as $item) {
            // Any allocation touching the item lets the production reconcile
            // recompute its status (and the parent CreditNote's) end to end.
            $allocation = PaymentAllocation::query()
                ->where(fn ($q) => $q->where('credit_schedule_item_id', $item->id)
                    ->orWhere('payment_schedule_item_id', $item->id))
                ->first();

            if (! $allocation) {
                $this->warn(sprintf(
                    'SKIP item #%d "%s" — PAID sem nenhuma alocação; revisar manualmente.',
                    $item->id,
                    $item->label,
                ));
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                'RELEASE item #%d "%s" — consumido %d de %d, saldo %d.',
                $item->id,
                $item->label,
                $item->credit_consumed_amount,
                $item->amount,
                $item->credit_remaining_amount,
            ));

            if ($dryRun) {
                $released++;

                continue;
            }

            $reconcile->forAllocation($allocation);
            $released++;
        }

        $this->info(sprintf(
            '%s%d item(ns) liberado(s), %d pulado(s).',
            $dryRun ? '[dry-run] ' : '',
            $released,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
