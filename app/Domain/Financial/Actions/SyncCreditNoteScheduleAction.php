<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile an issued CreditNote's credit PaymentScheduleItems with its
 * current line items — same job as SyncDebitNoteScheduleAction but for the
 * credit side: orphaned credit PSIs keep offering stale credit amounts in
 * the payment form, while new lines have no credit PSI at all.
 */
class SyncCreditNoteScheduleAction
{
    public function __construct(protected IssueCreditNoteAction $issueAction) {}

    /**
     * @return string[] Human-readable change log (empty when nothing to do)
     */
    public function execute(CreditNote $creditNote, bool $dryRun = false): array
    {
        if ($creditNote->status === CreditNoteStatus::DRAFT) {
            return [];
        }

        // A cancelled note keeps no schedule at all — every unconsumed credit
        // PSI is stale, not only the ones pointing at deleted lines.
        $isCancelled = $creditNote->status === CreditNoteStatus::CANCELLED;

        $changes = [];

        DB::transaction(function () use ($creditNote, $dryRun, $isCancelled, &$changes) {
            $lineIds = $creditNote->lineItems()->pluck('id');

            $orphans = PaymentScheduleItem::query()
                ->where('payable_type', CreditNote::class)
                ->where('payable_id', $creditNote->id)
                ->where('source_type', CreditNoteLineItem::class)
                ->when(! $isCancelled, fn ($q) => $q->whereNotIn('source_id', $lineIds))
                ->get();

            foreach ($orphans as $orphan) {
                $hasAllocations = $orphan->allocations()->exists()
                    || $orphan->creditAllocations()->exists();

                if ($hasAllocations) {
                    $changes[] = sprintf(
                        'SKIP PSI #%d "%s" — órfão mas possui alocações; revisar manualmente.',
                        $orphan->id,
                        $orphan->label,
                    );

                    continue;
                }

                $changes[] = sprintf('DELETE PSI #%d "%s" (%d)', $orphan->id, $orphan->label, $orphan->amount);

                if (! $dryRun) {
                    $orphan->delete();
                }
            }

            if ($isCancelled) {
                return;
            }

            foreach ($creditNote->lineItems()->get() as $lineItem) {
                $psi = PaymentScheduleItem::query()
                    ->where('source_type', CreditNoteLineItem::class)
                    ->where('source_id', $lineItem->id)
                    ->first();

                if (! $psi) {
                    $changes[] = sprintf(
                        'CREATE PSI para linha #%d "%s" (%d)',
                        $lineItem->id,
                        $lineItem->description,
                        $lineItem->amount,
                    );

                    if (! $dryRun) {
                        $this->issueAction->createScheduleItem($creditNote, $lineItem);
                    }

                    continue;
                }

                $expectedLabel = $creditNote->reference.': '.$lineItem->description;

                if ($psi->amount !== $lineItem->amount || $psi->label !== $expectedLabel) {
                    $changes[] = sprintf(
                        'UPDATE PSI #%d: %d -> %d "%s"',
                        $psi->id,
                        $psi->amount,
                        $lineItem->amount,
                        $expectedLabel,
                    );

                    if (! $dryRun) {
                        $this->issueAction->createScheduleItem($creditNote, $lineItem);
                        $this->recalculateCreditStatus($psi->refresh());
                    }
                }
            }

            if (! $dryRun) {
                $creditNote->refresh()->recalculateStatus();
            }
        });

        return $changes;
    }

    /**
     * Credit PSIs are skipped by PaymentScheduleItem::recalculateStatus(),
     * so after an amount change reapply the consumption rule used by
     * ReconcileSettlementStateAction: PAID only when fully consumed.
     */
    protected function recalculateCreditStatus(PaymentScheduleItem $psi): void
    {
        if ($psi->status === PaymentScheduleStatus::WAIVED) {
            return;
        }

        $newStatus = ($psi->credit_consumed_amount > 0
            && $psi->credit_remaining_amount <= CreditNote::SETTLEMENT_TOLERANCE)
            ? PaymentScheduleStatus::PAID
            : PaymentScheduleStatus::PENDING;

        if ($psi->status !== $newStatus) {
            $psi->update(['status' => $newStatus->value]);
        }
    }
}
