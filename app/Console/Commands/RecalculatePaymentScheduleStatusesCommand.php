<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Console\Command;

/**
 * One-shot repair for schedule items whose stored status drifted from the
 * live paid_amount accessor.
 *
 * This surfaces historically corrupted rows caused by two prior bugs:
 *   1. Silent currency corruption that left paid_amount inflated, locking
 *      items at PAID even though the actual paid value was much smaller.
 *   2. Mass-delete of allocations via Query Builder skipping model events,
 *      leaving items stuck at PAID with paid_amount = 0.
 *
 * The command only manages PAID ↔ DUE transitions. PENDING and OVERDUE are
 * owned by the document lifecycle and the due-date scheduler respectively,
 * so an unpaid DUE item is never reverted to PENDING by this command.
 * WAIVED and credit items are never touched.
 */
class RecalculatePaymentScheduleStatusesCommand extends Command
{
    protected $signature = 'financial:recalculate-schedule-statuses
        {--dry-run : Show changes without applying}
        {--company= : Limit to items payable to/from this company id}';

    protected $description = 'Reconcile PaymentScheduleItem.status against the live paid_amount accessor. Use this after upgrading to fix historically stuck statuses.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $query = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereNot('status', PaymentScheduleStatus::WAIVED);

        if ($companyId !== null) {
            $query->where(function ($q) use ($companyId) {
                $q->whereHasMorph('payable', '*', function ($sub) use ($companyId) {
                    $sub->where('company_id', $companyId)
                        ->orWhere('supplier_company_id', $companyId);
                });
            });
        }

        $items = $query->get();

        $this->info("Checking {$items->count()} schedule items...");

        $changes = 0;

        foreach ($items as $item) {
            $paid = $item->paid_amount;

            // Only manage PAID ↔ DUE transitions. An unpaid DUE item is valid
            // (it is active, just not yet paid) and must not be reverted to
            // PENDING, which represents "not yet activated by the document
            // lifecycle".
            if ($item->is_paid_in_full) {
                $newStatus = PaymentScheduleStatus::PAID;
            } elseif ($item->status === PaymentScheduleStatus::PAID) {
                $newStatus = PaymentScheduleStatus::DUE;
            } else {
                continue;
            }

            if ($item->status === $newStatus) {
                continue;
            }

            $this->line(sprintf(
                '#%d %s: %s → %s (paid=%s / amount=%s %s)',
                $item->id,
                $item->label ?? '—',
                $item->status->value,
                $newStatus->value,
                number_format($paid / 10000, 2),
                number_format($item->amount / 10000, 2),
                $item->currency_code,
            ));

            if (! $dryRun) {
                $item->update(['status' => $newStatus->value]);
            }

            $changes++;
        }

        if ($changes === 0) {
            $this->info('All statuses are already consistent. Nothing to do.');

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$changes} schedule items.");

        if ($dryRun) {
            $this->warn('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
