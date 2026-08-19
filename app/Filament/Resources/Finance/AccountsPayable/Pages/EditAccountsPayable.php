<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use App\Filament\Resources\Finance\Concerns\HasPaymentBankFee;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountsPayable extends EditRecord
{
    use HasPaymentAllocationPersistence;
    use HasPaymentBankFee;
    use HasSaveAndReturnFormActions;

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

        return $this->hydrateBankFee($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingAllocations = $data['allocations'] ?? [];
        $this->pendingCreditApplications = $data['credit_applications'] ?? [];

        $data = $this->extractBankFee($data);

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

        $previousScheduleItemIds = $payment->allocations()
            ->pluck('payment_schedule_item_id')
            ->unique()
            ->toArray();

        $previousCreditItemIds = $payment->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->pluck('credit_schedule_item_id')
            ->unique()
            ->toArray();

        $payment->allocations()->delete();

        // Mass-delete via Query Builder bypasses the PaymentAllocation
        // deleted observer, so reconcile status here explicitly for every
        // schedule item that previously carried an allocation from this
        // payment. Without this, items stay stuck at PAID with paid_amount
        // reverting to 0, hiding them from the allocation list and AP
        // report.
        foreach (array_unique(array_merge($previousScheduleItemIds, $previousCreditItemIds)) as $itemId) {
            $item = PaymentScheduleItem::find($itemId);

            if (! $item) {
                continue;
            }

            // recalculateStatus() pula créditos por design — sem isto, um
            // crédito cuja aplicação foi removida na edição ficaria PAID
            // obsoleto (e sumiria da lista de créditos disponíveis).
            $item->is_credit
                ? app(\App\Domain\Financial\Actions\ReconcileSettlementStateAction::class)->recalculateCreditItemStatus($item)
                : $item->recalculateStatus();
        }

        $this->persistAllocations($payment, $payment->currency_code);
        $this->persistCreditApplications($payment);
        $this->persistBankFee($payment);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
