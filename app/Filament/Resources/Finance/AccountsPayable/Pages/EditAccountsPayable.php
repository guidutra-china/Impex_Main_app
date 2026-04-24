<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountsPayable extends EditRecord
{
    use HasPaymentAllocationPersistence;

    protected static string $resource = AccountsPayableResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Money::toMajor($data['amount']);

        $regularAllocations = $this->record->allocations()
            ->whereNull('credit_schedule_item_id')
            ->get();

        $data['allocations'] = $regularAllocations->map(fn ($alloc) => [
            'payment_schedule_item_id' => $alloc->payment_schedule_item_id,
            'document_currency_code' => $alloc->scheduleItem?->currency_code,
            'allocated_amount' => Money::toMajor($alloc->allocated_amount),
            'allocated_amount_in_document_currency' => Money::toMajor($alloc->allocated_amount_in_document_currency),
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

        $this->persistAllocations($payment, $payment->currency_code);
        $this->persistCreditApplications($payment);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
