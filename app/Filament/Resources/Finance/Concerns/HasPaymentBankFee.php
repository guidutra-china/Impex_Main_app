<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Actions\SyncPaymentBankFeeAction;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Carries the bank fee entered on the payment form between the form data and
 * the additional cost that represents it. Mirrors
 * HasPaymentAllocationPersistence: fields are stripped before the payment is
 * written, then applied once the payment exists.
 */
trait HasPaymentBankFee
{
    /** @var array<string, mixed>|null */
    protected ?array $pendingBankFee = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractBankFee(array $data): array
    {
        $this->pendingBankFee = filled($data['bank_fee_amount'] ?? null)
            ? [
                'amount' => $data['bank_fee_amount'],
                'currency_code' => $data['bank_fee_currency_code'] ?? null,
                'billable_to' => $data['bank_fee_billable_to'] ?? null,
                'process' => $data['bank_fee_process'] ?? null,
                'description' => $data['bank_fee_description'] ?? null,
            ]
            : null;

        unset(
            $data['bank_fee_amount'],
            $data['bank_fee_currency_code'],
            $data['bank_fee_billable_to'],
            $data['bank_fee_process'],
            $data['bank_fee_description'],
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function hydrateBankFee(array $data): array
    {
        $fee = $this->record?->bankFeeCost()->first();

        if (! $fee) {
            return $data;
        }

        $data['bank_fee_amount'] = Money::toMajor($fee->amount);
        $data['bank_fee_currency_code'] = $fee->currency_code;
        $data['bank_fee_billable_to'] = $fee->billable_to?->value;
        $data['bank_fee_process'] = $fee->costable_type.':'.$fee->costable_id;
        $data['bank_fee_description'] = $fee->description;

        return $data;
    }

    protected function persistBankFee(Payment $payment): void
    {
        try {
            app(SyncPaymentBankFeeAction::class)->execute($payment, $this->pendingBankFee);
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first())
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
