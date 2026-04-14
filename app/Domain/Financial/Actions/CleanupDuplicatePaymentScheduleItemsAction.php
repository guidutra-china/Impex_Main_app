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
 * IMPORTANT: A single Shipment may legitimately contain schedule items from
 * multiple PIs for the same stage (e.g. 30% before shipment for PI-1, PI-2, PI-3).
 * These are NOT duplicates. The only way to distinguish per-PI items is via the
 * label, which contains "[SH-XX / PI-YY]". We extract the PI reference from the
 * label and use it as part of the grouping key.
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

        // Fetch all candidate items (shipment-linked or per-stage base items)
        $allItems = PaymentScheduleItem::query()
            ->whereNull('source_type')
            ->whereNotNull('payment_term_stage_id')
            ->with('allocations')
            ->orderBy('id')
            ->get();

        // Group by a composite key that includes the PI reference (extracted from label).
        // Key format: payable_type|payable_id|stage_id|shipment_id|pi_ref
        $groups = $allItems->groupBy(function ($item) {
            return implode('|', [
                $item->payable_type,
                $item->payable_id,
                $item->payment_term_stage_id,
                $item->shipment_id ?? 'null',
                $this->extractPiReference($item->label) ?? 'null',
            ]);
        });

        $duplicateGroups = $groups->filter(fn ($group) => $group->count() > 1);
        $totalDuplicatesFound = $duplicateGroups->count();

        if ($totalDuplicatesFound === 0) {
            return [
                'duplicates_found' => 0,
                'items_deleted' => 0,
                'details' => [],
            ];
        }

        DB::transaction(function () use ($duplicateGroups, $dryRun, &$totalDeleted, &$details) {
            foreach ($duplicateGroups as $groupKey => $items) {
                // Rank: PAID > WAIVED > has allocations > oldest (stable)
                $sorted = $items->sort(function ($a, $b) {
                    $rankA = $this->rankItem($a);
                    $rankB = $this->rankItem($b);
                    if ($rankA !== $rankB) {
                        return $rankA <=> $rankB;
                    }

                    // Tiebreaker: more allocations first
                    $allocA = $a->allocations->count();
                    $allocB = $b->allocations->count();
                    if ($allocA !== $allocB) {
                        return $allocB <=> $allocA;
                    }

                    // Final tiebreaker: oldest (lowest id)
                    return $a->id <=> $b->id;
                })->values();

                $keeper = $sorted->first();
                $toDelete = $sorted->slice(1);

                foreach ($toDelete as $duplicate) {
                    // Safety: never delete items that have allocations
                    if ($duplicate->allocations->isNotEmpty()) {
                        $details[] = [
                            'action' => 'SKIPPED',
                            'item_id' => $duplicate->id,
                            'kept_item_id' => $keeper->id,
                            'payable' => $duplicate->payable_type . '#' . $duplicate->payable_id,
                            'stage_id' => $duplicate->payment_term_stage_id,
                            'shipment_id' => $duplicate->shipment_id,
                            'status' => $duplicate->status->value,
                            'amount' => $duplicate->amount,
                            'label' => $duplicate->label . ' (has allocations)',
                        ];
                        continue;
                    }

                    $details[] = [
                        'action' => $dryRun ? 'WOULD_DELETE' : 'DELETED',
                        'item_id' => $duplicate->id,
                        'kept_item_id' => $keeper->id,
                        'payable' => $duplicate->payable_type . '#' . $duplicate->payable_id,
                        'stage_id' => $duplicate->payment_term_stage_id,
                        'shipment_id' => $duplicate->shipment_id,
                        'status' => $duplicate->status->value,
                        'amount' => $duplicate->amount,
                        'label' => $duplicate->label,
                    ];

                    if (! $dryRun) {
                        $duplicate->delete();
                    }
                    $totalDeleted++;
                }

                // After cleanup, sync keeper's status with actual allocations
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

    /**
     * Extract the PI/PO reference from a schedule item label.
     * Label format: "30% — Before Shipment — [SH-2026-00015 / PI-2026-00011]"
     * or:           "30% — Before Shipment — [PI-2026-00011]"
     */
    protected function extractPiReference(?string $label): ?string
    {
        if (! $label) {
            return null;
        }

        // Match "/ PI-XXXX]" or "/ PO-XXXX]" (shipment + doc format)
        if (preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', $label, $m)) {
            return $m[1];
        }

        // Match "[PI-XXXX]" or "[PO-XXXX]" (remaining/base format)
        if (preg_match('#\[((?:PI|PO)-[\w-]+)\]#', $label, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Rank items for preservation priority (lower = better kept).
     */
    protected function rankItem(PaymentScheduleItem $item): int
    {
        if ($item->status === PaymentScheduleStatus::PAID) {
            return 1;
        }
        if ($item->status === PaymentScheduleStatus::WAIVED) {
            return 2;
        }
        if ($item->allocations->isNotEmpty()) {
            return 3;
        }

        return 4;
    }
}
