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
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $pendingAllocations = [];

    protected array $pendingCreditApplications = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Money::toMajor($data['amount']);

        $regularAllocations = $this->record->allocations()
            ->whereNull('credit_schedule_item_id')
            ->get();

        $data['allocations'] = $regularAllocations->map(fn ($alloc) => [
            'payment_schedule_item_id' => $alloc->payment_schedule_item_id,
            'allocated_amount' => Money::toMajor($alloc->allocated_amount),
            'exchange_rate' => $alloc->exchange_rate,
        ])->toArray();

        $creditAllocations = $this->record->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->get();

        $data['credit_applications'] = $creditAllocations->map(fn ($alloc) => [
            'credit_schedule_item_id' => $alloc->credit_schedule_item_id,
            'payment_schedule_item_id' => $alloc->payment_schedule_item_id,
            'credit_amount' => Money::toMajor($alloc->allocated_amount_in_document_currency),
        ])->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingAllocations = $data['allocations'] ?? [];
        $this->pendingCreditApplications = $data['credit_applications'] ?? [];

        $data['amount'] = Money::toMinor((float) $data['amount']);
        $data['status'] = PaymentStatus::PENDING_APPROVAL->value;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        unset($data['allocations'], $data['credit_applications']);

        return $data;
    }

    protected function afterSave(): void
    {
        $payment = $this->record;

        $previousCreditItemIds = $payment->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->pluck('credit_schedule_item_id')
            ->unique()
            ->toArray();

        $payment->allocations()->delete();

        foreach ($previousCreditItemIds as $creditItemId) {
            $creditItem = PaymentScheduleItem::find($creditItemId);
            if ($creditItem && $creditItem->status === PaymentScheduleStatus::PAID) {
                $hasOtherApplications = PaymentAllocation::where('credit_schedule_item_id', $creditItemId)
                    ->exists();

                if (! $hasOtherApplications) {
                    $creditItem->update(['status' => PaymentScheduleStatus::PENDING->value]);
                }
            }
        }

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

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
