<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;

class PopulateScheduleComponentsFromProductAction
{
    /**
     * Copy product BOM components into ProductionScheduleComponents
     * for each PI item in the schedule. Skips items that already have components.
     */
    public function execute(ProductionSchedule $schedule): int
    {
        $schedule->load(['proformaInvoice.items.product.components']);

        $created = 0;

        foreach ($schedule->proformaInvoice->items as $piItem) {
            if (!$piItem->product || $piItem->product->components->isEmpty()) {
                continue;
            }

            // Skip if this PI item already has components in this schedule
            $existingCount = ProductionScheduleComponent::where([
                'production_schedule_id'   => $schedule->id,
                'proforma_invoice_item_id' => $piItem->id,
            ])->whereNotNull('component_name')->count();

            if ($existingCount > 0) {
                continue;
            }

            foreach ($piItem->product->components as $bomComponent) {
                ProductionScheduleComponent::create([
                    'production_schedule_id'   => $schedule->id,
                    'proforma_invoice_item_id' => $piItem->id,
                    'component_name'           => $bomComponent->name,
                    'quantity_required'        => (int) ($bomComponent->quantity_required * $piItem->quantity),
                    'status'                   => ComponentStatus::AtSupplier,
                    'supplier_name'            => $bomComponent->default_supplier_name,
                    'eta'                      => null,
                    'notes'                    => null,
                    'updated_by'               => null,
                ]);
                $created++;
            }
        }

        return $created;
    }
}
