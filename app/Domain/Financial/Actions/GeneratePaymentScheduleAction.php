<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
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

        return $created;
    }

    public function regenerate(Model $payable): int
    {
        PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        $paymentTermId = $payable->payment_term_id;

        if (! $paymentTermId) {
            return 0;
        }

        $paymentTerm = PaymentTerm::with('stages')->find($paymentTermId);

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return 0;
        }

        $existingStageIds = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->pluck('payment_term_stage_id')
            ->toArray();

        $totalAmount = $payable->total;
        $currencyCode = $payable->currency_code;
        $created = 0;

        foreach ($paymentTerm->stages as $stage) {
            if (in_array($stage->id, $existingStageIds)) {
                continue;
            }

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

        return $created;
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
        }

        return implode(' — ', $parts);
    }
}
