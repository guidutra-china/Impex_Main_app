<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile an issued DebitNote's PaymentScheduleItems with its current line
 * items. Needed because line items can change after issue (the edit page
 * historically deleted and recreated them, orphaning the PSIs created at
 * issue time — DN-2026-0006 case): orphaned PSIs point at deleted lines and
 * keep offering stale amounts for allocation, while new lines have no PSI
 * at all.
 */
class SyncDebitNoteScheduleAction
{
    public function __construct(protected IssueDebitNoteAction $issueAction) {}

    /**
     * @return string[] Human-readable change log (empty when nothing to do)
     */
    public function execute(DebitNote $debitNote, bool $dryRun = false): array
    {
        if ($debitNote->status === DebitNoteStatus::DRAFT) {
            return [];
        }

        // A cancelled note keeps no schedule at all — every unallocated PSI
        // is stale, not only the ones pointing at deleted lines.
        $isCancelled = $debitNote->status === DebitNoteStatus::CANCELLED;

        $changes = [];

        DB::transaction(function () use ($debitNote, $dryRun, $isCancelled, &$changes) {
            $lineIds = $debitNote->lineItems()->pluck('id');

            $orphans = PaymentScheduleItem::query()
                ->where('payable_type', DebitNote::class)
                ->where('payable_id', $debitNote->id)
                ->where('source_type', DebitNoteLineItem::class)
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

            foreach ($debitNote->lineItems()->get() as $lineItem) {
                $psi = PaymentScheduleItem::query()
                    ->where('source_type', DebitNoteLineItem::class)
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
                        $this->issueAction->createScheduleItem($debitNote, $lineItem);
                    }

                    continue;
                }

                $expectedLabel = $debitNote->reference.': '.$lineItem->description;

                if ($psi->amount !== $lineItem->amount || $psi->label !== $expectedLabel) {
                    $changes[] = sprintf(
                        'UPDATE PSI #%d: %d -> %d "%s"',
                        $psi->id,
                        $psi->amount,
                        $lineItem->amount,
                        $expectedLabel,
                    );

                    if (! $dryRun) {
                        $this->issueAction->createScheduleItem($debitNote, $lineItem);
                        $psi->refresh()->recalculateStatus();
                    }
                }
            }
        });

        return $changes;
    }
}
