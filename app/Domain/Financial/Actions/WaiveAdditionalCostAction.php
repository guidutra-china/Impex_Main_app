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
 *
 * Rules:
 * - Each side column (status, forwarder_status, supplier_payable_status) is
 *   only overwritten with WAIVED when that side isn't already PAID — waive
 *   does not erase a settled payment.
 * - Schedule items already PAID keep their history untouched; only
 *   unpaid/partially-allocated items are waived.
 */
class WaiveAdditionalCostAction
{
    public function execute(AdditionalCost $cost, ?int $userId): void
    {
        $updates = ['status' => AdditionalCostStatus::WAIVED];

        if ($cost->forwarder_company_id && $cost->forwarder_amount
            && $cost->forwarder_status !== AdditionalCostStatus::PAID) {
            $updates['forwarder_status'] = AdditionalCostStatus::WAIVED;
        }

        if ($cost->hasSupplierPayableSide() && $cost->supplier_payable_status !== AdditionalCostStatus::PAID) {
            $updates['supplier_payable_status'] = AdditionalCostStatus::WAIVED;
        }

        $cost->update($updates);

        PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->whereNot('status', PaymentScheduleStatus::PAID)
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
