<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;

/**
 * Waives an AdditionalCost and every schedule item derived from it (client,
 * forwarder and supplier-payable rows). Extracted from
 * AdditionalCostsRelationManager::waiveCostAction so the supplier-side column
 * is covered by tests.
 */
class WaiveAdditionalCostAction
{
    public function execute(AdditionalCost $cost, ?int $userId): void
    {
        $updates = ['status' => AdditionalCostStatus::WAIVED];

        if ($cost->hasSupplierPayableSide()) {
            $updates['supplier_payable_status'] = AdditionalCostStatus::WAIVED;
        }

        $cost->update($updates);

        PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->get()
            ->each(function ($scheduleItem) use ($userId) {
                $scheduleItem->update([
                    'status' => PaymentScheduleStatus::WAIVED,
                    'waived_by' => $userId,
                    'waived_at' => now(),
                ]);
            });
    }
}
