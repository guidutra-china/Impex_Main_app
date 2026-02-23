<?php

namespace App\Domain\Financial\Traits;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPaymentSchedule
{
    public function paymentScheduleItems(): MorphMany
    {
        return $this->morphMany(PaymentScheduleItem::class, 'payable')->orderBy('sort_order');
    }

    public function hasPaymentSchedule(): bool
    {
        return $this->paymentScheduleItems()->exists();
    }

    public function getScheduleTotalAttribute(): int
    {
        return $this->paymentScheduleItems->sum('amount');
    }

    public function getSchedulePaidTotalAttribute(): int
    {
        $scheduleItemIds = $this->paymentScheduleItems()->pluck('id');

        if ($scheduleItemIds->isEmpty()) {
            return 0;
        }

        return (int) PaymentAllocation::whereIn('payment_schedule_item_id', $scheduleItemIds)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    public function getScheduleRemainingAttribute(): int
    {
        return max(0, $this->schedule_total - $this->schedule_paid_total);
    }

    public function getPaymentProgressAttribute(): float
    {
        if ($this->schedule_total <= 0) {
            return 0;
        }

        return round(($this->schedule_paid_total / $this->schedule_total) * 100, 1);
    }

    public function hasUnresolvedBlockingPayments(string $targetStatus): bool
    {
        $blockers = PaymentScheduleItem::blockingConditionsForTransition($this, $targetStatus);

        return count($blockers) > 0;
    }

    public function getBlockingPaymentLabels(string $targetStatus): array
    {
        $blockers = PaymentScheduleItem::blockingConditionsForTransition($this, $targetStatus);

        return array_map(fn (PaymentScheduleItem $item) => $item->label, $blockers);
    }
}
