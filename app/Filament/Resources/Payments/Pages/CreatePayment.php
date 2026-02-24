<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $pendingAllocations = [];

    protected array $pendingCreditApplications = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingAllocations = $data['allocations'] ?? [];
        $this->pendingCreditApplications = $data['credit_applications'] ?? [];

        $data['amount'] = Money::toMinor((float) $data['amount']);
        $data['status'] = PaymentStatus::PENDING_APPROVAL->value;

        unset($data['allocations'], $data['credit_applications']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $payment = $this->record;
        $paymentCurrencyCode = $payment->currency_code;

        $this->persistAllocations($payment, $paymentCurrencyCode);
        $this->persistCreditApplications($payment);
    }

    protected function persistAllocations($payment, string $paymentCurrencyCode): void
    {
        foreach ($this->pendingAllocations as $allocationData) {
            $scheduleItemId = $allocationData['payment_schedule_item_id'] ?? null;

            if (! $scheduleItemId) {
                continue;
            }

            $scheduleItem = PaymentScheduleItem::find($scheduleItemId);

            if (! $scheduleItem) {
                continue;
            }

            $allocatedMinor = Money::toMinor((float) ($allocationData['allocated_amount'] ?? 0));

            if ($allocatedMinor <= 0) {
                continue;
            }

            $exchangeRate = ! empty($allocationData['exchange_rate']) ? (float) $allocationData['exchange_rate'] : null;
            $documentCurrencyCode = $scheduleItem->currency_code;
            $allocatedInDocCurrency = $allocatedMinor;

            if ($paymentCurrencyCode !== $documentCurrencyCode) {
                if ($exchangeRate) {
                    $allocatedInDocCurrency = (int) round($allocatedMinor * $exchangeRate);
                } else {
                    $paymentCurrency = Currency::where('code', $paymentCurrencyCode)->first();
                    $documentCurrency = Currency::where('code', $documentCurrencyCode)->first();

                    if ($paymentCurrency && $documentCurrency) {
                        $converted = ExchangeRate::convert(
                            $paymentCurrency->id,
                            $documentCurrency->id,
                            Money::toMajor($allocatedMinor)
                        );

                        if ($converted !== null) {
                            $allocatedInDocCurrency = Money::toMinor($converted);
                            $exchangeRate = $allocatedInDocCurrency / max($allocatedMinor, 1);
                        }
                    }
                }
            }

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $scheduleItemId,
                'allocated_amount' => $allocatedMinor,
                'exchange_rate' => $exchangeRate,
                'allocated_amount_in_document_currency' => $allocatedInDocCurrency,
            ]);
        }
    }

    protected function persistCreditApplications($payment): void
    {
        foreach ($this->pendingCreditApplications as $creditData) {
            $creditItemId = $creditData['credit_schedule_item_id'] ?? null;
            $scheduleItemId = $creditData['payment_schedule_item_id'] ?? null;
            $creditAmount = (float) ($creditData['credit_amount'] ?? 0);

            if (! $creditItemId || ! $scheduleItemId || $creditAmount <= 0) {
                continue;
            }

            $creditItem = PaymentScheduleItem::find($creditItemId);
            $scheduleItem = PaymentScheduleItem::find($scheduleItemId);

            if (! $creditItem || ! $scheduleItem) {
                continue;
            }

            $creditMinor = Money::toMinor($creditAmount);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $scheduleItemId,
                'credit_schedule_item_id' => $creditItemId,
                'allocated_amount' => 0,
                'exchange_rate' => null,
                'allocated_amount_in_document_currency' => $creditMinor,
            ]);

            $creditItem->update([
                'status' => PaymentScheduleStatus::PAID->value,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
