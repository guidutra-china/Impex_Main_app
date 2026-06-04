<?php

namespace App\Domain\Travel\Actions;

use App\Domain\Travel\DataTransferObjects\TripBillingData;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use Illuminate\Support\Facades\DB;

class ApproveTripAction
{
    public function __construct(
        private readonly PostTripFinancialsAction $postFinancials,
    ) {}

    public function approve(Trip $trip, ?TripBillingData $billing = null): void
    {
        // Guard against double-posting if approve is somehow called twice.
        if ($trip->status === TripStatus::APPROVED) {
            return;
        }

        // Status change and the financial posting commit atomically.
        DB::transaction(function () use ($trip, $billing) {
            $trip->update([
                'status' => TripStatus::APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);

            // Approval is the trigger for billing: internal → company expense,
            // client/supplier → debit note in the chosen billing currency.
            $this->postFinancials->execute($trip, $billing);
        });
    }

    public function reject(Trip $trip, string $reason): void
    {
        $trip->update([
            'status' => TripStatus::REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejected_reason' => $reason,
        ]);
    }
}
