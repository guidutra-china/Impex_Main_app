<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnose (and optionally repair) the gap between a Debit Note's live
 * paid_amount and its stored `status` column.
 *
 * The `status` column is NOT computed: it only transitions to PAID inside
 * PaymentAllocationObserver::checkDebitNoteReconciliation(), which fires on
 * PaymentAllocation created/deleted events and requires every PaymentScheduleItem
 * sourced from the DN's line items to have stored status === PAID. Meanwhile the
 * "Paid" badge in the UI is the live paid_amount accessor (sum of APPROVED
 * allocations). The two diverge when a PSI status drifted, a payment was
 * approved after its allocation (no event re-fired), allocations were bulk
 * inserted (bypassing model events), or the DN predates the reconciliation
 * logic (legacy data).
 *
 * Read-only by default. With --fix it recalculates each PSI status and then
 * re-applies the observer's decision to the DN status, all in a transaction.
 */
class DiagnoseDebitNotesCommand extends Command
{
    protected $signature = 'debit-notes:diagnose
        {references?* : DN references (e.g. DN-2026-0001). Empty = scan all inconsistent DNs}
        {--fix : Reconcile PSI statuses and the DN status (without it, read-only)}';

    protected $description = 'Diagnose why a Debit Note status is stuck out of sync with its paid_amount, and optionally reconcile it.';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $references = (array) $this->argument('references');

        $debitNotes = $this->resolveDebitNotes($references);

        if ($debitNotes->isEmpty()) {
            $this->info('No matching Debit Notes.');

            return self::SUCCESS;
        }

        foreach ($debitNotes as $debitNote) {
            $this->diagnose($debitNote);

            if ($fix) {
                $this->fix($debitNote);
            }
        }

        if (! $fix) {
            $this->newLine();
            $this->warn('Read-only run. Re-run with --fix to reconcile the stuck statuses.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $references
     * @return \Illuminate\Support\Collection<int, DebitNote>
     */
    protected function resolveDebitNotes(array $references)
    {
        if (! empty($references)) {
            $found = DebitNote::whereIn('reference', $references)->get();

            $missing = array_diff($references, $found->pluck('reference')->all());
            foreach ($missing as $ref) {
                $this->warn("Reference not found: {$ref}");
            }

            return $found;
        }

        // Scan: DNs not yet paid/cancelled whose live paid_amount already covers
        // the total. paid_amount is an accessor, so filter in PHP.
        return DebitNote::whereIn('status', [
            DebitNoteStatus::ISSUED,
            DebitNoteStatus::PARTIALLY_PAID,
        ])
            ->get()
            ->filter(fn (DebitNote $dn) => $dn->paid_amount >= (int) $dn->total_amount)
            ->values();
    }

    protected function diagnose(DebitNote $debitNote): void
    {
        $this->newLine();
        $this->line("=== <info>{$debitNote->reference}</info> ===");
        $this->line(sprintf('Status:    %s', $debitNote->status->value));
        $this->line(sprintf('Currency:  %s', $debitNote->currency_code));
        $this->line(sprintf('Total:     %s', $this->money($debitNote->total_amount)));
        $this->line(sprintf('Paid:      %s', $this->money($debitNote->paid_amount)));
        $this->line(sprintf('Remaining: %s', $this->money($debitNote->remaining_amount)));

        $scheduleItems = $this->scheduleItems($debitNote);

        if ($scheduleItems->isEmpty()) {
            $this->warn('No PaymentScheduleItems sourced from this DN — it was likely never issued, so no schedule exists to reconcile.');

            return;
        }

        $rows = $scheduleItems->map(function (PaymentScheduleItem $psi) {
            $paymentStatuses = $psi->allocations
                ->map(fn ($a) => $a->payment?->status?->value ?? '—')
                ->implode(', ');

            return [
                $psi->id,
                $psi->label ?? '—',
                $psi->status->value,
                $this->money($psi->amount),
                $this->money($psi->paid_amount),
                $psi->is_paid_in_full ? 'yes' : 'no',
                $psi->allocations->count(),
                $paymentStatuses !== '' ? $paymentStatuses : '—',
            ];
        })->all();

        $this->table(
            ['PSI', 'Label', 'Status', 'Amount', 'Paid', 'Full?', 'Allocs', 'Payment status'],
            $rows,
        );

        $this->verdict($debitNote, $scheduleItems);
    }

    protected function verdict(DebitNote $debitNote, $scheduleItems): void
    {
        $allPaid = $scheduleItems->every(fn ($i) => $i->status === PaymentScheduleStatus::PAID);
        $coversTotal = $debitNote->paid_amount >= (int) $debitNote->total_amount;
        $isPaidStatus = $debitNote->status === DebitNoteStatus::PAID;

        if ($isPaidStatus) {
            $this->info('Verdict: consistent — status is PAID.');

            return;
        }

        if (! $coversTotal) {
            $this->line('Verdict: status is not PAID and paid_amount does not yet cover the total — this is expected, the DN is genuinely not fully paid.');

            return;
        }

        // paid_amount covers the total but the status column did not flip.
        $blocking = $scheduleItems->filter(fn ($i) => $i->status !== PaymentScheduleStatus::PAID);

        $this->warn('Verdict: paid_amount covers the total but status is NOT PAID — stuck/out of sync.');

        if ($allPaid) {
            $this->line('  All PSIs are stored as PAID, yet the DN status was never transitioned (no allocation event re-fired the observer). --fix will reconcile it.');

            return;
        }

        $this->line('  The following PSIs have a stored status != PAID, which blocks the observer from setting the DN to PAID:');
        foreach ($blocking as $psi) {
            $this->line(sprintf(
                '    - PSI #%d "%s": stored=%s, paid=%s/%s, is_paid_in_full=%s',
                $psi->id,
                $psi->label ?? '—',
                $psi->status->value,
                $this->money($psi->paid_amount),
                $this->money($psi->amount),
                $psi->is_paid_in_full ? 'yes' : 'no',
            ));
        }
        $this->line('  --fix calls recalculateStatus() on each PSI (PAID ↔ DUE against live paid_amount), then re-applies the DN transition.');
    }

    protected function fix(DebitNote $debitNote): void
    {
        if ($debitNote->status === DebitNoteStatus::CANCELLED) {
            $this->warn("  Skipping --fix: {$debitNote->reference} is CANCELLED.");

            return;
        }

        $from = $debitNote->status->value;

        DB::transaction(function () use ($debitNote) {
            foreach ($this->scheduleItems($debitNote) as $psi) {
                $psi->recalculateStatus();
            }

            // Re-apply the observer's reconciliation rule with fresh PSI statuses.
            $scheduleItems = $this->scheduleItems($debitNote);

            if ($scheduleItems->isEmpty()) {
                return;
            }

            $allPaid = $scheduleItems->every(fn ($i) => $i->status === PaymentScheduleStatus::PAID);
            $anyPaid = $scheduleItems->contains(fn ($i) => $i->status === PaymentScheduleStatus::PAID);

            if ($allPaid) {
                $debitNote->update(['status' => DebitNoteStatus::PAID]);
            } elseif ($anyPaid) {
                $debitNote->update(['status' => DebitNoteStatus::PARTIALLY_PAID]);
            } elseif ($debitNote->status !== DebitNoteStatus::ISSUED) {
                $debitNote->update(['status' => DebitNoteStatus::ISSUED]);
            }
        });

        $to = $debitNote->refresh()->status->value;

        if ($from === $to) {
            $this->info("  Fix: {$debitNote->reference} already consistent ({$to}).");
        } else {
            $this->info("  Fix: {$debitNote->reference} {$from} → {$to}.");
        }
    }

    /**
     * Schedule items sourced from this DN's line items — same lookup the
     * observer uses (PaymentAllocationObserver::checkDebitNoteReconciliation).
     *
     * @return \Illuminate\Support\Collection<int, PaymentScheduleItem>
     */
    protected function scheduleItems(DebitNote $debitNote)
    {
        $lineItemIds = $debitNote->lineItems()->pluck('id');

        if ($lineItemIds->isEmpty()) {
            return collect();
        }

        return PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->whereIn('source_id', $lineItemIds)
            ->with('allocations.payment')
            ->get();
    }

    protected function money(int $minorUnits): string
    {
        return number_format($minorUnits / Money::SCALE, 2);
    }
}
