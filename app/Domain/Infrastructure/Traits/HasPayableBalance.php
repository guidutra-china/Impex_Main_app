<?php

namespace App\Domain\Infrastructure\Traits;

use App\Domain\Infrastructure\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPayableBalance
{
    public function paymentAllocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'payable');
    }

    /**
     * Returns the total amount of this payable document (in minor units).
     * Each model MUST implement this to return its total.
     */
    abstract public function getPayableTotal(): int;

    /**
     * Returns the currency code of this payable document.
     */
    abstract public function getPayableCurrency(): string;

    /**
     * Returns the payment direction expected for this payable.
     * 'incoming' for documents where we receive money (PI, ClientInvoice).
     * 'outgoing' for documents where we pay money (PO).
     */
    abstract public function getPayableDirection(): string;

    /**
     * Computed: sum of all allocations for this payable.
     */
    public function getComputedPaidAmount(): int
    {
        return (int) $this->paymentAllocations()->sum('amount');
    }

    /**
     * Computed: remaining balance = total - paid.
     */
    public function getComputedBalance(): int
    {
        return $this->getPayableTotal() - $this->getComputedPaidAmount();
    }

    /**
     * Check if the cached paid_amount matches the computed value.
     * Returns the drift amount (0 = no drift).
     */
    public function getBalanceDrift(): int
    {
        if (! isset($this->attributes['paid_amount'])) {
            return 0;
        }

        return $this->paid_amount - $this->getComputedPaidAmount();
    }

    /**
     * Recalculate and update the cached paid_amount field.
     */
    public function reconcileBalance(): void
    {
        if (! isset($this->attributes['paid_amount'])) {
            return;
        }

        $computed = $this->getComputedPaidAmount();

        if ($this->paid_amount !== $computed) {
            $this->updateQuietly(['paid_amount' => $computed]);
        }
    }
}
