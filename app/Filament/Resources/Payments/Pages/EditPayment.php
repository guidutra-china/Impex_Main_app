<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Money::toMajor($data['amount']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['amount'] = Money::toMinor((float) $data['amount']);
        $data['status'] = PaymentStatus::PENDING_APPROVAL->value;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        return $data;
    }

    protected function afterSave(): void
    {
        $payment = $this->record;
        $data = $this->form->getState();

        // Delete existing allocations and recreate
        $payment->allocations()->delete();

        if (empty($data['allocations'])) {
            return;
        }

        $paymentCurrencyCode = $payment->currency_code;

        foreach ($data['allocations'] as $allocationData) {
            $scheduleItem = \App\Domain\Financial\Models\PaymentScheduleItem::find($allocationData['payment_schedule_item_id']);

            if (! $scheduleItem) {
                continue;
            }

            $allocatedMinor = Money::toMinor((float) $allocationData['allocated_amount']);
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
                'payment_schedule_item_id' => $allocationData['payment_schedule_item_id'],
                'allocated_amount' => $allocatedMinor,
                'exchange_rate' => $exchangeRate,
                'allocated_amount_in_document_currency' => $allocatedInDocCurrency,
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
