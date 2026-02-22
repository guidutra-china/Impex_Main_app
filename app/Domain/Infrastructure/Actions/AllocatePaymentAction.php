<?php

namespace App\Domain\Infrastructure\Actions;

use App\Domain\Infrastructure\Models\PaymentAllocation;
use App\Domain\Infrastructure\Traits\HasPayableBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AllocatePaymentAction
{
    /**
     * Allocate an amount from a payment to a payable document.
     *
     * @param  int  $paymentId
     * @param  Model&HasPayableBalance  $payable
     * @param  int  $amount  Amount in minor units
     * @return PaymentAllocation
     *
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    public function execute(int $paymentId, Model $payable, int $amount): PaymentAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Allocation amount must be positive.');
        }

        return DB::transaction(function () use ($paymentId, $payable, $amount) {
            $payable->lockForUpdate()->find($payable->getKey());

            $currentPaid = $payable->getComputedPaidAmount();
            $total = $payable->getPayableTotal();
            $remaining = $total - $currentPaid;

            if ($amount > $remaining) {
                $modelClass = class_basename($payable);
                throw new \OverflowException(
                    "Allocation of {$amount} exceeds remaining balance of {$remaining} "
                    . "on {$modelClass} #{$payable->getKey()}."
                );
            }

            $existingPaymentAllocations = PaymentAllocation::where('payment_id', $paymentId)->sum('amount');
            // Note: payment total validation will be done when Payment model exists

            $allocation = PaymentAllocation::create([
                'payment_id' => $paymentId,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'amount' => $amount,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            if (isset($payable->attributes['paid_amount'])) {
                $payable->updateQuietly([
                    'paid_amount' => $currentPaid + $amount,
                ]);
            }

            return $allocation;
        });
    }

    /**
     * Remove an allocation and update the cached balance.
     */
    public function deallocate(PaymentAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation) {
            $payable = $allocation->payable;

            $allocation->delete();

            if ($payable && isset($payable->attributes['paid_amount'])) {
                $payable->reconcileBalance();
            }
        });
    }
}
