<?php

namespace App\Filament\Resources\Finance\AccountsReceivable\Pages;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountsReceivable extends EditRecord
{
    use HasPaymentAllocationPersistence;
    use HasSaveAndReturnFormActions;

    protected static string $resource = AccountsReceivableResource::class;

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

        // Crédito aninhado na linha da parcela (forma do formulário).
        $data['allocations'] = \App\Domain\Financial\Support\AllocationFormShape::nestCredits(
            $data['allocations'],
            $creditAllocations->map(fn ($alloc) => [
                'credit_schedule_item_id' => $alloc->credit_schedule_item_id,
                'payment_schedule_item_id' => $alloc->payment_schedule_item_id,
                'credit_amount' => Money::toMajor($alloc->allocated_amount_in_document_currency),
                'document_currency_code' => $alloc->scheduleItem?->currency_code,
            ])->all(),
        );

        // Parcelas de uma mesma DN voltam a ser uma linha só na tela.
        $data['allocations'] = \App\Domain\Financial\Support\DebitNoteBundle::collapseRows($data['allocations']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // DN com várias linhas chega como uma linha só; aqui vira uma por parcela.
        $this->pendingAllocations = \App\Domain\Financial\Support\DebitNoteBundle::expandRows($data['allocations'] ?? [], $this->record->getKey());
        $this->pendingCreditApplications = \App\Domain\Financial\Support\AllocationFormShape::flattenCredits($this->pendingAllocations);

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

        $this->releaseAllocations($payment);

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
}
