<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GeneratePaymentScheduleAction
{
    public function execute(Model $payable): int
    {
        $paymentTermId = $payable->payment_term_id;

        if (! $paymentTermId) {
            return 0;
        }

        $paymentTerm = PaymentTerm::with('stages')->find($paymentTermId);

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return 0;
        }

        $existingCount = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->count();

        if ($existingCount > 0) {
            return 0;
        }

        $totalAmount = $payable->total;
        $currencyCode = $payable->currency_code;
        $created = 0;

        foreach ($paymentTerm->stages as $stage) {
            $amount = (int) round($totalAmount * ($stage->percentage / 100));

            $isBlocking = $this->isBlockingCondition($stage->calculation_base);

            $dueDate = $this->calculateDueDate($payable, $stage);

            $label = $this->generateLabel($stage);

            PaymentScheduleItem::create([
                'payable_type' => get_class($payable),
                'payable_id' => $payable->getKey(),
                'payment_term_stage_id' => $stage->id,
                'label' => $label,
                'percentage' => $stage->percentage,
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'due_condition' => $stage->calculation_base,
                'due_date' => $dueDate,
                'status' => PaymentScheduleStatus::PENDING,
                'is_blocking' => $isBlocking,
                'sort_order' => $stage->sort_order,
            ]);

            $created++;
        }

        $this->syncAdditionalCosts($payable);

        return $created;
    }

    public function regenerate(Model $payable): int
    {
        return DB::transaction(function () use ($payable) {
            $processed = $this->regenerateBaseItems($payable);

            // For PI/PO: also recalculate shipment-dependent items across all linked shipments
            if ($payable instanceof ProformaInvoice) {
                $this->regenerateShipmentItemsForPi($payable);
            } elseif ($payable instanceof PurchaseOrder) {
                $this->regenerateShipmentItemsForPo($payable);
            }

            $this->syncAdditionalCosts($payable);

            // Recalculate statuses based on actual allocations
            $this->recalculateAllStatuses($payable);

            return $processed;
        });
    }

    protected function regenerateBaseItems(Model $payable): int
    {
        $paymentTermId = $payable->payment_term_id;

        if (! $paymentTermId) {
            return 0;
        }

        $paymentTerm = PaymentTerm::with('stages')->find($paymentTermId);

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return 0;
        }

        // Calculate total directly from DB — avoids any Eloquent caching
        $totalAmount = $this->calculateTotalFromDb($payable);
        $currencyCode = $payable->currency_code;

        // Step 1: Delete ALL deletable schedule items (not paid/waived, no allocations)
        PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->whereNull('source_type')
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        // Step 2: Force-update amounts on ANY surviving items (paid/waived/with allocations)
        $survivingItems = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->whereNull('source_type')
            ->get();

        foreach ($survivingItems as $item) {
            if ($item->percentage > 0) {
                $correctAmount = (int) round($totalAmount * ($item->percentage / 100));
                PaymentScheduleItem::where('id', $item->id)->update(['amount' => $correctAmount]);
            }
        }

        // Step 3: Create missing base items for non-shipment-dependent stages
        $existingStageIds = $survivingItems
            ->whereNull('shipment_plan_id')
            ->whereNull('shipment_id')
            ->pluck('payment_term_stage_id')
            ->filter()
            ->all();

        $processed = 0;

        foreach ($paymentTerm->stages as $stage) {
            if ($stage->calculation_base?->isShipmentDependent()) {
                $processed++;
                continue;
            }

            // Skip if a surviving item already covers this stage
            if (in_array($stage->id, $existingStageIds)) {
                $processed++;
                continue;
            }

            $newAmount = (int) round($totalAmount * ($stage->percentage / 100));

            PaymentScheduleItem::create([
                'payable_type' => get_class($payable),
                'payable_id' => $payable->getKey(),
                'payment_term_stage_id' => $stage->id,
                'label' => $this->generateLabel($stage),
                'percentage' => $stage->percentage,
                'amount' => $newAmount,
                'currency_code' => $currencyCode,
                'due_condition' => $stage->calculation_base,
                'due_date' => $this->calculateDueDate($payable, $stage),
                'status' => PaymentScheduleStatus::PENDING,
                'is_blocking' => $this->isBlockingCondition($stage->calculation_base),
                'sort_order' => $stage->sort_order,
            ]);

            $processed++;
        }

        return $processed;
    }

    // ─── Shipment-dependent items for Proforma Invoice ───

    protected function regenerateShipmentItemsForPi(ProformaInvoice $pi): void
    {
        $paymentTerm = $pi->paymentTerm;

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return;
        }

        $shipmentDependentStages = $paymentTerm->stages->filter(
            fn ($stage) => $stage->calculation_base?->isShipmentDependent()
        );

        if ($shipmentDependentStages->isEmpty()) {
            return;
        }

        $piType = get_class($pi);

        // Step 1: Identify stages covered by base items (no shipment_id) that are paid/waived or have allocations
        $coveredStageIds = PaymentScheduleItem::where('payable_type', $piType)
            ->where('payable_id', $pi->id)
            ->whereNull('shipment_id')
            ->whereNull('source_type')
            ->where('label', 'NOT LIKE', '%[remaining]%')
            ->where(function ($q) {
                $q->whereIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ])->orWhereHas('allocations');
            })
            ->pluck('payment_term_stage_id')
            ->filter()
            ->all();

        // Step 2: Delete shipment-specific items AND remaining items that can be regenerated
        PaymentScheduleItem::where('payable_type', $piType)
            ->where('payable_id', $pi->id)
            ->whereNull('source_type')
            ->where(function ($q) {
                $q->whereNotNull('shipment_id')
                  ->orWhere('label', 'LIKE', '%[remaining]%');
            })
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        // Step 3: Find all shipments linked to this PI
        $shipments = Shipment::whereHas('items.proformaInvoiceItem', function ($q) use ($pi) {
            $q->where('proforma_invoice_id', $pi->id);
        })->with(['items.proformaInvoiceItem' => fn ($q) => $q->where('proforma_invoice_id', $pi->id)])
            ->get();

        $totalShippedValue = 0;
        $sortOffset = PaymentScheduleItem::where('payable_type', $piType)
            ->where('payable_id', $pi->id)
            ->max('sort_order') ?? 0;

        foreach ($shipments as $shipment) {
            $shipmentValue = $shipment->items
                ->filter(fn ($item) => $item->proformaInvoiceItem && $item->proformaInvoiceItem->proforma_invoice_id === $pi->id)
                ->sum(fn ($item) => $item->proformaInvoiceItem->unit_price * $item->quantity);

            $totalShippedValue += $shipmentValue;

            if ($shipmentValue <= 0) {
                continue;
            }

            foreach ($shipmentDependentStages as $stage) {
                // Skip if a base item already covers this stage (paid/with allocations)
                if (in_array($stage->id, $coveredStageIds)) {
                    continue;
                }

                // Check for surviving item (paid/waived/with allocations — not deleted above)
                $existingItem = PaymentScheduleItem::where('payable_type', $piType)
                    ->where('payable_id', $pi->id)
                    ->where('shipment_id', $shipment->id)
                    ->where('payment_term_stage_id', $stage->id)
                    ->first();

                if ($existingItem) {
                    // Update amount to match current shipment value
                    $correctAmount = (int) round($shipmentValue * ($stage->percentage / 100));
                    $existingItem->update(['amount' => $correctAmount]);
                    continue;
                }

                $amount = (int) round($shipmentValue * ($stage->percentage / 100));
                $dueDate = $this->calculateShipmentDueDate($shipment, $stage);
                $label = $this->generateShipmentLabel($stage, $pi->reference, $shipment->reference);
                $isBlocking = $stage->calculation_base === CalculationBase::BEFORE_SHIPMENT;

                PaymentScheduleItem::create([
                    'payable_type' => $piType,
                    'payable_id' => $pi->id,
                    'shipment_id' => $shipment->id,
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $amount,
                    'currency_code' => $pi->currency_code,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => $dueDate,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => $isBlocking,
                    'sort_order' => ++$sortOffset,
                ]);
            }
        }

        // Step 4: Create "remaining" items for unshipped value
        $remainingValue = max(0, $pi->total - $totalShippedValue);

        if ($remainingValue > 0) {
            foreach ($shipmentDependentStages as $stage) {
                // Skip if a base item already covers this stage
                if (in_array($stage->id, $coveredStageIds)) {
                    continue;
                }

                // Skip if a remaining item survived deletion (paid/with allocations)
                $existingRemaining = PaymentScheduleItem::where('payable_type', $piType)
                    ->where('payable_id', $pi->id)
                    ->whereNull('shipment_id')
                    ->where('payment_term_stage_id', $stage->id)
                    ->where('label', 'LIKE', '%[remaining]%')
                    ->first();

                if ($existingRemaining) {
                    $correctAmount = (int) round($remainingValue * ($stage->percentage / 100));
                    $existingRemaining->update(['amount' => $correctAmount]);
                    continue;
                }

                $amount = (int) round($remainingValue * ($stage->percentage / 100));
                $label = $this->generateShipmentLabel($stage, $pi->reference, null) . ' [remaining]';

                PaymentScheduleItem::create([
                    'payable_type' => $piType,
                    'payable_id' => $pi->id,
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $amount,
                    'currency_code' => $pi->currency_code,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => null,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => false,
                    'sort_order' => ++$sortOffset,
                ]);
            }
        }
    }

    // ─── Shipment-dependent items for Purchase Order ───

    protected function regenerateShipmentItemsForPo(PurchaseOrder $po): void
    {
        $paymentTerm = $po->paymentTerm;

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return;
        }

        $shipmentDependentStages = $paymentTerm->stages->filter(
            fn ($stage) => $stage->calculation_base?->isShipmentDependent()
        );

        if ($shipmentDependentStages->isEmpty()) {
            return;
        }

        $poType = get_class($po);

        // Step 1: Identify stages covered by base items that are paid/waived or have allocations
        $coveredStageIds = PaymentScheduleItem::where('payable_type', $poType)
            ->where('payable_id', $po->id)
            ->whereNull('shipment_id')
            ->whereNull('source_type')
            ->where('label', 'NOT LIKE', '%[remaining]%')
            ->where(function ($q) {
                $q->whereIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ])->orWhereHas('allocations');
            })
            ->pluck('payment_term_stage_id')
            ->filter()
            ->all();

        // Step 2: Delete shipment-specific and remaining items that can be regenerated
        PaymentScheduleItem::where('payable_type', $poType)
            ->where('payable_id', $po->id)
            ->whereNull('source_type')
            ->where(function ($q) {
                $q->whereNotNull('shipment_id')
                  ->orWhere('label', 'LIKE', '%[remaining]%');
            })
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        // Step 3: Find all shipments linked to this PO
        $shipments = Shipment::whereHas('items.purchaseOrderItem', function ($q) use ($po) {
            $q->where('purchase_order_id', $po->id);
        })->with(['items.purchaseOrderItem' => fn ($q) => $q->where('purchase_order_id', $po->id)])
            ->get();

        $totalShippedValue = 0;
        $sortOffset = PaymentScheduleItem::where('payable_type', $poType)
            ->where('payable_id', $po->id)
            ->max('sort_order') ?? 0;

        foreach ($shipments as $shipment) {
            $shipmentValue = $shipment->items
                ->filter(fn ($item) => $item->purchaseOrderItem && $item->purchaseOrderItem->purchase_order_id === $po->id)
                ->sum(fn ($item) => $item->purchaseOrderItem->unit_cost * $item->quantity);

            $totalShippedValue += $shipmentValue;

            if ($shipmentValue <= 0) {
                continue;
            }

            foreach ($shipmentDependentStages as $stage) {
                if (in_array($stage->id, $coveredStageIds)) {
                    continue;
                }

                $existingItem = PaymentScheduleItem::where('payable_type', $poType)
                    ->where('payable_id', $po->id)
                    ->where('shipment_id', $shipment->id)
                    ->where('payment_term_stage_id', $stage->id)
                    ->first();

                if ($existingItem) {
                    $correctAmount = (int) round($shipmentValue * ($stage->percentage / 100));
                    $existingItem->update(['amount' => $correctAmount]);
                    continue;
                }

                $amount = (int) round($shipmentValue * ($stage->percentage / 100));
                $dueDate = $this->calculateShipmentDueDate($shipment, $stage);
                $label = $this->generateShipmentLabel($stage, $po->reference, $shipment->reference);
                $isBlocking = $stage->calculation_base === CalculationBase::BEFORE_SHIPMENT;

                PaymentScheduleItem::create([
                    'payable_type' => $poType,
                    'payable_id' => $po->id,
                    'shipment_id' => $shipment->id,
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $amount,
                    'currency_code' => $po->currency_code,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => $dueDate,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => $isBlocking,
                    'sort_order' => ++$sortOffset,
                ]);
            }
        }

        // Step 4: Create "remaining" items for unshipped value
        $remainingValue = max(0, $po->total - $totalShippedValue);

        if ($remainingValue > 0) {
            foreach ($shipmentDependentStages as $stage) {
                if (in_array($stage->id, $coveredStageIds)) {
                    continue;
                }

                $existingRemaining = PaymentScheduleItem::where('payable_type', $poType)
                    ->where('payable_id', $po->id)
                    ->whereNull('shipment_id')
                    ->where('payment_term_stage_id', $stage->id)
                    ->where('label', 'LIKE', '%[remaining]%')
                    ->first();

                if ($existingRemaining) {
                    $correctAmount = (int) round($remainingValue * ($stage->percentage / 100));
                    $existingRemaining->update(['amount' => $correctAmount]);
                    continue;
                }

                $amount = (int) round($remainingValue * ($stage->percentage / 100));
                $label = $this->generateShipmentLabel($stage, $po->reference, null) . ' [remaining]';

                PaymentScheduleItem::create([
                    'payable_type' => $poType,
                    'payable_id' => $po->id,
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $amount,
                    'currency_code' => $po->currency_code,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => null,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => false,
                    'sort_order' => ++$sortOffset,
                ]);
            }
        }
    }

    // ─── Shipment-specific: one schedule per PI ───

    public function executeForShipment(Shipment $shipment): int
    {
        $shipment->loadMissing(['items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages']);

        // Group shipment items by PI
        $itemsByPi = $shipment->items
            ->filter(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice)
            ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id);

        $created = 0;
        $sortOrder = 0;

        foreach ($itemsByPi as $piId => $shipmentItems) {
            $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;
            $paymentTerm = $pi->paymentTerm;

            if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
                continue;
            }

            // Calculate value of this PI's items in this shipment
            $piValue = $shipmentItems->sum(function ($item) {
                $piItem = $item->proformaInvoiceItem;
                return $piItem ? $piItem->unit_price * $item->quantity : 0;
            });

            if ($piValue <= 0) {
                continue;
            }

            // Only shipment-dependent stages (before shipment, delivery date, etc.)
            // Non-shipment stages (upfront, order date) stay on the PI schedule
            $shipmentStages = $paymentTerm->stages->filter(
                fn ($stage) => $stage->calculation_base?->isShipmentDependent()
            );

            foreach ($shipmentStages as $stage) {
                $amount = (int) round($piValue * ($stage->percentage / 100));
                $dueDate = $this->calculateShipmentDueDate($shipment, $stage);
                $label = $this->generateShipmentLabel($stage, $pi->reference, $shipment->reference);

                PaymentScheduleItem::create([
                    'payable_type' => get_class($shipment),
                    'payable_id' => $shipment->id,
                    'shipment_id' => $shipment->id,
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $amount,
                    'currency_code' => $pi->currency_code ?? $shipment->currency_code,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => $dueDate,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => $this->isBlockingCondition($stage->calculation_base),
                    'sort_order' => ++$sortOrder,
                ]);

                $created++;
            }
        }

        // Also update each PI's schedule to split shipped vs remaining
        foreach ($itemsByPi as $piId => $shipmentItems) {
            $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;
            if ($pi->paymentTerm && $pi->hasPaymentSchedule()) {
                $this->regenerateShipmentItemsForPi($pi);
            }
        }

        $this->syncAdditionalCosts($shipment);

        return $created;
    }

    public function regenerateForShipment(Shipment $shipment): int
    {
        return DB::transaction(function () use ($shipment) {
            // Delete all deletable schedule items
            PaymentScheduleItem::where('payable_type', get_class($shipment))
                ->where('payable_id', $shipment->id)
                ->whereNull('source_type')
                ->whereNotIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ])
                ->whereDoesntHave('allocations')
                ->delete();

            $count = $this->executeForShipment($shipment);

            return $count;
        });
    }

    // ─── Shared helpers ───

    protected function syncAdditionalCosts(Model $payable): void
    {
        if (! method_exists($payable, 'additionalCosts')) {
            return;
        }

        $costs = $payable->additionalCosts()
            ->whereNotIn('status', ['waived'])
            ->get();

        // First, remove orphaned additional cost schedule items (cost was deleted/waived)
        $activeCostIds = $costs->pluck('id')->all();
        PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('source_type', AdditionalCost::class)
            ->whereNotIn('source_id', $activeCostIds)
            ->whereDoesntHave('allocations')
            ->delete();

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->max('sort_order') ?? 0;

        foreach ($costs as $cost) {
            $billableTo = $cost->billable_to instanceof BillableTo ? $cost->billable_to : BillableTo::from($cost->billable_to);

            if ($billableTo === BillableTo::COMPANY) {
                continue;
            }

            $schedulePayable = $this->resolvePayableForCost($cost, $payable, $billableTo);
            if (! $schedulePayable) {
                continue;
            }

            $isCredit = $billableTo === BillableTo::SUPPLIER;
            $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
                ? $cost->cost_type->getLabel()
                : $cost->cost_type;

            $label = $isCredit
                ? "Credit: {$cost->description}"
                : "{$costTypeLabel}: {$cost->description}";

            // --- Client receivable item ---
            $existingClient = PaymentScheduleItem::where('source_type', AdditionalCost::class)
                ->where('source_id', $cost->id)
                ->where(function ($q) {
                    $q->whereNull('notes')
                        ->orWhere('notes', 'NOT LIKE', '%[forwarder-payable]%');
                })
                ->first();

            if (! $existingClient) {
                $maxSortOrder++;
                PaymentScheduleItem::create([
                    'payable_type' => get_class($schedulePayable),
                    'payable_id' => $schedulePayable->getKey(),
                    'label' => mb_substr($label, 0, 100),
                    'percentage' => 0,
                    'amount' => $cost->amount_in_document_currency,
                    'currency_code' => $schedulePayable->currency_code ?? $cost->currency_code ?? 'USD',
                    'status' => PaymentScheduleStatus::DUE->value,
                    'is_blocking' => false,
                    'is_credit' => $isCredit,
                    'source_type' => AdditionalCost::class,
                    'source_id' => $cost->id,
                    'sort_order' => $maxSortOrder,
                    'notes' => $cost->notes,
                ]);
            } else {
                $existingClient->update([
                    'label' => mb_substr($label, 0, 100),
                    'amount' => $cost->amount_in_document_currency,
                    'is_credit' => $isCredit,
                ]);
            }

            // --- Forwarder payable item ---
            if ($cost->forwarder_amount_in_document_currency && $cost->forwarder_company_id) {
                $forwarderTag = '[forwarder-payable]';
                $forwarderName = $cost->forwarderCompany?->name ?? 'Forwarder';
                $forwarderLabel = mb_substr("{$costTypeLabel} payable: {$forwarderName} - {$cost->description}", 0, 100);

                $existingForwarder = PaymentScheduleItem::where('source_type', AdditionalCost::class)
                    ->where('source_id', $cost->id)
                    ->where('notes', 'LIKE', "%{$forwarderTag}%")
                    ->first();

                if (! $existingForwarder) {
                    $maxSortOrder++;
                    PaymentScheduleItem::create([
                        'payable_type' => get_class($schedulePayable),
                        'payable_id' => $schedulePayable->getKey(),
                        'label' => $forwarderLabel,
                        'percentage' => 0,
                        'amount' => $cost->forwarder_amount_in_document_currency,
                        'currency_code' => $schedulePayable->currency_code ?? $cost->forwarder_currency_code ?? 'USD',
                        'status' => PaymentScheduleStatus::DUE->value,
                        'is_blocking' => false,
                        'is_credit' => false,
                        'source_type' => AdditionalCost::class,
                        'source_id' => $cost->id,
                        'sort_order' => $maxSortOrder,
                        'notes' => "{$forwarderTag} {$cost->notes}",
                    ]);
                } else {
                    $existingForwarder->update([
                        'label' => $forwarderLabel,
                        'amount' => $cost->forwarder_amount_in_document_currency,
                    ]);
                }
            }
        }
    }

    protected function resolvePayableForCost(AdditionalCost $cost, Model $owner, BillableTo $billableTo): ?Model
    {
        if ($billableTo === BillableTo::CLIENT) {
            if ($owner instanceof ProformaInvoice) {
                return $owner;
            }
            if ($owner instanceof PurchaseOrder) {
                return $owner->proformaInvoice;
            }
        }

        if ($billableTo === BillableTo::SUPPLIER) {
            if ($owner instanceof PurchaseOrder) {
                return $owner;
            }
            if ($owner instanceof ProformaInvoice) {
                $po = $owner->purchaseOrders()->first();

                return $po ?: $owner;
            }
        }

        return $owner;
    }

    protected function isBlockingCondition(?CalculationBase $condition): bool
    {
        if (! $condition) {
            return false;
        }

        return in_array($condition, [
            CalculationBase::BEFORE_PRODUCTION,
            CalculationBase::BEFORE_SHIPMENT,
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE,
        ]);
    }

    protected function calculateDueDate(Model $payable, $stage): ?\Carbon\Carbon
    {
        $baseDate = match ($stage->calculation_base) {
            CalculationBase::ORDER_DATE => $payable->issue_date ?? $payable->created_at,
            CalculationBase::PO_DATE => $payable->issue_date ?? $payable->created_at,
            CalculationBase::INVOICE_DATE => $payable->issue_date ?? $payable->created_at,
            default => null,
        };

        if (! $baseDate) {
            return null;
        }

        if ($baseDate instanceof \Carbon\Carbon || $baseDate instanceof \Illuminate\Support\Carbon) {
            return $stage->days != 0 ? $baseDate->copy()->addDays($stage->days) : $baseDate->copy();
        }

        $parsed = \Carbon\Carbon::parse($baseDate);

        return $stage->days != 0 ? $parsed->addDays($stage->days) : $parsed;
    }

    protected function calculateShipmentDueDate(Shipment $shipment, $stage): ?\Carbon\Carbon
    {
        $baseDate = match ($stage->calculation_base) {
            CalculationBase::BEFORE_SHIPMENT,
            CalculationBase::SHIPMENT_DATE,
            CalculationBase::BL_DATE => $shipment->etd,
            CalculationBase::DELIVERY_DATE => $shipment->eta,
            default => null,
        };

        if (! $baseDate) {
            return null;
        }

        if ($stage->calculation_base === CalculationBase::BEFORE_SHIPMENT) {
            return $baseDate->copy()->subDays(max($stage->days, 2));
        }

        return $stage->days != 0
            ? $baseDate->copy()->addDays($stage->days)
            : $baseDate->copy();
    }

    protected function generateLabel($stage): string
    {
        $parts = [$stage->percentage . '%'];

        if ($stage->calculation_base) {
            $conditionLabel = $stage->calculation_base->getLabel();
            $parts[] = $conditionLabel;
        }

        if ($stage->days > 0) {
            $parts[] = '(+' . $stage->days . ' days)';
        } elseif ($stage->days < 0) {
            $parts[] = '(' . $stage->days . ' days)';
        }

        return implode(' — ', $parts);
    }

    protected function generateShipmentLabel($stage, string $docReference, ?string $shipmentReference): string
    {
        $parts = [
            $stage->percentage . '%',
            $stage->calculation_base->getLabel(),
        ];

        if ($stage->days > 0) {
            $parts[] = '(+' . $stage->days . ' days)';
        } elseif ($stage->days < 0) {
            $parts[] = '(' . $stage->days . ' days)';
        }

        if ($shipmentReference) {
            $parts[] = '[' . $shipmentReference . ' / ' . $docReference . ']';
        } else {
            $parts[] = '[' . $docReference . ']';
        }

        return implode(' — ', $parts);
    }

    protected function calculateTotalFromDb(Model $payable): int
    {
        if ($payable instanceof ProformaInvoice) {
            return (int) $payable->items()
                ->selectRaw('SUM(unit_price * quantity) as total')
                ->value('total');
        }

        if ($payable instanceof PurchaseOrder) {
            return (int) $payable->items()
                ->selectRaw('SUM(unit_cost * quantity) as total')
                ->value('total');
        }

        return 0;
    }

    protected function recalculateAllStatuses(Model $payable): void
    {
        $items = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->get();

        foreach ($items as $item) {
            if ($item->status === PaymentScheduleStatus::WAIVED) {
                continue;
            }

            $item->refresh();

            if ($item->is_paid_in_full) {
                $newStatus = PaymentScheduleStatus::PAID;
            } elseif ($item->paid_amount > 0) {
                $newStatus = PaymentScheduleStatus::DUE;
            } else {
                $newStatus = $item->status === PaymentScheduleStatus::DUE
                    ? PaymentScheduleStatus::DUE
                    : PaymentScheduleStatus::PENDING;
            }

            if ($item->status !== $newStatus) {
                $item->update(['status' => $newStatus]);
            }
        }
    }
}
