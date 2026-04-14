<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cleans up duplicate PaymentScheduleItem records created by the regenerate bug.
 *
 * The bug: regenerateForShipment() deleted unpaid items without allocations,
 * then executeForShipment() re-created them blindly — producing duplicates
 * when a paid/allocated item survived deletion but a new one was also created.
 *
 * This action finds groups with the same (payable_type, payable_id, payment_term_stage_id, shipment_id)
 * that have more than one item, keeps the "best" one (paid > with allocations > oldest),
 * and deletes the orphan duplicates (only those with no allocations).
 */
class CleanupDuplicatePaymentScheduleItemsAction
{
    /**
     * Run cleanup. Returns array with stats.
     *
     * @param  bool  $dryRun  If true, only report what would be deleted without actually deleting.
     * @return array{duplicates_found: int, items_deleted: int, details: array}
     */
    public function execute(bool $dryRun = false): array
    {
        $details = [];
        $totalDeleted = 0;
        $totalDuplicatesFound = 0;

        // Find all groups that have duplicates
        $duplicateGroups = PaymentScheduleItem::query()
            ->select('payable_type', 'payable_id', 'payment_term_stage_id', 'shipment_id')
            ->whereNull('source_type') // Exclude additional cost items
            ->whereNotNull('payment_term_stage_id')
            ->groupBy('payable_type', 'payable_id', 'payment_term_stage_id', 'shipment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return [
                'duplicates_found' => 0,
                'items_deleted' => 0,
                'details' => [],
            ];
        }

        $totalDuplicatesFound = $duplicateGroups->count();

        DB::transaction(function () use ($duplicateGroups, $dryRun, &$totalDeleted, &$details) {
            foreach ($duplicateGroups as $group) {
                $items = PaymentScheduleItem::where('payable_type', $group->payable_type)
                    ->where('payable_id', $group->payable_id)
                    ->where('payment_term_stage_id', $group->payment_term_stage_id)
                    ->where(function ($q) use ($group) {
                        if ($group->shipment_id) {
                            $q->where('shipment_id', $group->shipment_id);
                        } else {
                            $q->whereNull('shipment_id');
                        }
                    })
                    ->whereNull('source_type')
                    ->with('allocations')
                    ->orderByRaw("
                        CASE
                            WHEN status = ? THEN 1
                            WHEN status = ? THEN 2
                            ELSE 3
                        END ASC
                    ", [PaymentScheduleStatus::PAID->value, PaymentScheduleStatus::WAIVED->value])
                    ->orderByRaw('(SELECT COUNT(*) FROM payment_allocations WHERE payment_schedule_item_id = payment_schedule_items.id) DESC')
                    ->orderBy('created_at', 'asc') // oldest first as tiebreaker
                    ->get();

                if ($items->count() <= 1) {
                    continue;
                }

                // Keep the first one (best ranked), delete the rest if they have no allocations
                $keeper = $items->first();
                $toDelete = $items->slice(1);

                foreach ($toDelete as $duplicate) {
                    // Safety: never delete items that have allocations
                    if ($duplicate->allocations->isNotEmpty()) {
                        $details[] = [
                            'action' => 'SKIPPED',
                            'item_id' => $duplicate->id,
                            'payable' => $group->payable_type . '#' . $group->payable_id,
                            'stage_id' => $group->payment_term_stage_id,
                            'shipment_id' => $group->shipment_id,
                            'status' => $duplicate->status->value,
                            'reason' => 'Has allocations — requires manual review',
                            'kept_item_id' => $keeper->id,
                        ];
                        continue;
                    }

                    $details[] = [
                        'action' => $dryRun ? 'WOULD_DELETE' : 'DELETED',
                        'item_id' => $duplicate->id,
                        'payable' => $group->payable_type . '#' . $group->payable_id,
                        'stage_id' => $group->payment_term_stage_id,
                        'shipment_id' => $group->shipment_id,
                        'status' => $duplicate->status->value,
                        'amount' => $duplicate->amount,
                        'label' => $duplicate->label,
                        'kept_item_id' => $keeper->id,
                    ];

                    if (! $dryRun) {
                        $duplicate->delete();
                        $totalDeleted++;
                    } else {
                        $totalDeleted++;
                    }
                }

                // After cleanup, sync the keeper's status with its actual allocations
                if (! $dryRun && $keeper->status !== PaymentScheduleStatus::WAIVED) {
                    $keeper->refresh();
                    if ($keeper->is_paid_in_full) {
                        $keeper->update(['status' => PaymentScheduleStatus::PAID]);
                    } elseif ($keeper->paid_amount > 0) {
                        $keeper->update(['status' => PaymentScheduleStatus::DUE]);
                    }
                }
            }
        });

        Log::info('CleanupDuplicatePaymentScheduleItems completed', [
            'dry_run' => $dryRun,
            'duplicates_found' => $totalDuplicatesFound,
            'items_deleted' => $totalDeleted,
        ]);

        return [
            'duplicates_found' => $totalDuplicatesFound,
            'items_deleted' => $totalDeleted,
            'details' => $details,
        ];
    }
}
