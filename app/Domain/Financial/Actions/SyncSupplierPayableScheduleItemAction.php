<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Upserts/removes the [supplier-payable] PaymentScheduleItem of an
 * AdditionalCost: the amount Impex owes a supplier for this cost. Anchored on
 * the cost's owner document (PI or Shipment), mirroring the forwarder-payable
 * pattern used by FREIGHT costs. Called from both sync paths
 * (AdditionalCostsRelationManager and GeneratePaymentScheduleAction) so the
 * row survives schedule regeneration.
 */
class SyncSupplierPayableScheduleItemAction
{
    public function execute(AdditionalCost $cost, Model $owner): void
    {
        $tag = PaymentScheduleItem::SUPPLIER_PAYABLE_TAG;

        $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag($tag)
            ->first();

        if (! $cost->hasSupplierPayableSide()) {
            if ($existing && ! $existing->allocations()->exists()) {
                $existing->delete();
            }

            return;
        }

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getEnglishLabel()
            : $cost->cost_type;

        $supplierName = $cost->supplierCompany?->name ?? 'Supplier';
        $label = mb_substr("{$costTypeLabel} payable: {$supplierName} - {$cost->description}", 0, 100);

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($owner))
            ->where('payable_id', $owner->getKey())
            ->max('sort_order') ?? 0;

        $scheduleData = [
            'payable_type' => get_class($owner),
            'payable_id' => $owner->getKey(),
            'label' => $label,
            'percentage' => 0,
            'amount' => $cost->supplier_payable_amount,
            'currency_code' => $cost->supplier_payable_currency_code ?? $owner->currency_code ?? 'USD',
            'due_date' => $cost->supplier_payable_due_date,
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => $maxSortOrder + 1,
            'notes' => trim("{$tag} ".($cost->notes ?? '')),
        ];

        if ($existing) {
            if ($existing->allocations()->exists()) {
                $existing->update([
                    'label' => $scheduleData['label'],
                    'amount' => $scheduleData['amount'],
                    'currency_code' => $scheduleData['currency_code'],
                    'due_date' => $scheduleData['due_date'],
                    'notes' => $scheduleData['notes'],
                ]);
            } else {
                $existing->update($scheduleData);
            }
        } else {
            PaymentScheduleItem::create($scheduleData);
        }
    }

    /**
     * Form-side guard: turning the side off (or switching supplier) after a
     * payment was allocated against the payable row must be blocked — the
     * allocation was made against that counterparty.
     */
    public static function assertSideRemovable(AdditionalCost $cost, bool $willHaveSide, ?int $newSupplierId): void
    {
        $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->first();

        if (! $existing || ! $existing->allocations()->exists()) {
            return;
        }

        $supplierChanged = $willHaveSide && (int) $newSupplierId !== (int) $cost->supplier_company_id;

        if (! $willHaveSide) {
            throw ValidationException::withMessages([
                'has_supplier_payable' => __('forms.validation.supplier_payable_has_allocations'),
            ]);
        }

        if ($supplierChanged) {
            throw ValidationException::withMessages([
                'supplier_company_id' => __('forms.validation.supplier_payable_has_allocations'),
            ]);
        }
    }
}
