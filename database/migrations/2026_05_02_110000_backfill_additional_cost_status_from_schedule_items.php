<?php

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $statusFor = fn (?string $psiStatus) => match ($psiStatus) {
            PaymentScheduleStatus::PAID->value => AdditionalCostStatus::PAID->value,
            PaymentScheduleStatus::WAIVED->value => AdditionalCostStatus::WAIVED->value,
            PaymentScheduleStatus::DUE->value,
            PaymentScheduleStatus::OVERDUE->value => AdditionalCostStatus::INVOICED->value,
            default => AdditionalCostStatus::PENDING->value,
        };

        // Pull every PSI sourced from an AdditionalCost in chunks; for each,
        // route the status to the right column (client vs forwarder side)
        // using the same [forwarder-payable] note tag the observer reads.
        PaymentScheduleItem::query()
            ->where('source_type', AdditionalCost::class)
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($statusFor) {
                foreach ($items as $psi) {
                    $cost = AdditionalCost::find($psi->source_id);
                    if (! $cost) {
                        continue;
                    }

                    $isForwarder = str_contains($psi->notes ?? '', '[forwarder-payable]');
                    $column = $isForwarder ? 'forwarder_status' : 'status';
                    $newValue = $statusFor($psi->status?->value);

                    if ($cost->{$column}?->value !== $newValue) {
                        $cost->{$column} = $newValue;
                        $cost->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Backfill is idempotent and not safely reversible.
    }
};
