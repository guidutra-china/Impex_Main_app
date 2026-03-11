<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Support\Facades\DB;

class UpdatePaymentScheduleFromProductionAction
{
    /**
     * Update AFTER_PRODUCTION payment schedule items when overall PI production readiness
     * crosses each item's percentage threshold.
     *
     * Returns array of updated PaymentScheduleItem IDs.
     */
    public function execute(ProductionSchedule $schedule): array
    {
        $schedule->load('entries', 'proformaInvoice.items');
        $updatedItems = [];

        DB::transaction(function () use ($schedule, &$updatedItems) {
            $pi = $schedule->proformaInvoice;
            if (! $pi) {
                return;
            }

            // Calculate OVERALL PI readiness (not per-item)
            $totalPlanned = $pi->items->sum('quantity');
            if ($totalPlanned <= 0) {
                return;
            }

            $totalProduced = $schedule->entries->sum(fn ($e) => $e->actual_quantity ?? 0);
            $readinessPct = ($totalProduced / $totalPlanned) * 100;

            // Find qualifying payment items at PI level:
            // - AFTER_PRODUCTION condition
            // - PENDING status
            // - null due_date (idempotency guard — items already dated are never re-touched)
            $scheduleItems = PaymentScheduleItem::where('payable_type', get_class($pi))
                ->where('payable_id', $pi->id)
                ->where('due_condition', CalculationBase::AFTER_PRODUCTION)
                ->where('status', PaymentScheduleStatus::PENDING)
                ->whereNull('due_date')
                ->get();

            foreach ($scheduleItems as $item) {
                if ($readinessPct >= $item->percentage) {
                    $item->update(['due_date' => now()->toDateString()]);
                    $updatedItems[] = $item->id;
                }
            }
        });

        return $updatedItems;
    }
}
