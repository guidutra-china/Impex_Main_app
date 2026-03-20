<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;

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
        $paymentTermId = $payable->payment_term_id;

        if (! $paymentTermId) {
            return 0;
        }

        $paymentTerm = PaymentTerm::with('stages')->find($paymentTermId);

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return 0;
        }

        $payable->load('items');
        $totalAmount = $payable->total;
        $currencyCode = $payable->currency_code;

        PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->whereNull('source_type')
            ->whereNull('shipment_plan_id')
            ->whereNull('shipment_id')
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        $preservedItems = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->whereNull('source_type')
            ->whereNull('shipment_plan_id')
            ->whereNull('shipment_id')
            ->get()
            ->keyBy('payment_term_stage_id');

        $processed = 0;

        foreach ($paymentTerm->stages as $stage) {
            $newAmount = (int) round($totalAmount * ($stage->percentage / 100));
            $isBlocking = $this->isBlockingCondition($stage->calculation_base);
            $dueDate = $this->calculateDueDate($payable, $stage);
            $label = $this->generateLabel($stage);

            $existing = $preservedItems->get($stage->id);

            if ($existing) {
                $existing->update([
                    'amount' => $newAmount,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'due_condition' => $stage->calculation_base,
                    'is_blocking' => $isBlocking,
                ]);
            } else {
                PaymentScheduleItem::create([
                    'payable_type' => get_class($payable),
                    'payable_id' => $payable->getKey(),
                    'payment_term_stage_id' => $stage->id,
                    'label' => $label,
                    'percentage' => $stage->percentage,
                    'amount' => $newAmount,
                    'currency_code' => $currencyCode,
                    'due_condition' => $stage->calculation_base,
                    'due_date' => $dueDate,
                    'status' => PaymentScheduleStatus::PENDING,
                    'is_blocking' => $isBlocking,
                    'sort_order' => $stage->sort_order,
                ]);
            }

            $processed++;
        }

        $this->syncAdditionalCosts($payable);

        return $processed;
    }

    protected function syncAdditionalCosts(Model $payable): void
    {
        if (! method_exists($payable, 'additionalCosts')) {
            return;
        }

        $costs = $payable->additionalCosts()
            ->whereNotIn('status', ['waived'])
            ->get();

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

            $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
                ->where('source_id', $cost->id)
                ->first();

            if ($existing) {
                continue;
            }

            $isCredit = $billableTo === BillableTo::SUPPLIER;
            $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
                ? $cost->cost_type->getLabel()
                : $cost->cost_type;

            $label = $isCredit
                ? "Credit: {$cost->description}"
                : "{$costTypeLabel}: {$cost->description}";

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
            return $stage->days > 0 ? $baseDate->copy()->addDays($stage->days) : $baseDate->copy();
        }

        $parsed = \Carbon\Carbon::parse($baseDate);

        return $stage->days > 0 ? $parsed->addDays($stage->days) : $parsed;
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
}
