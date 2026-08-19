<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use App\Filament\Resources\Finance\Concerns\HasPaymentBankFee;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountsPayable extends CreateRecord
{
    use HasPaymentAllocationPersistence;
    use HasPaymentBankFee;

    protected static string $resource = AccountsPayableResource::class;

    public function mount(): void
    {
        parent::mount();

        $prefill = $this->buildScheduleItemsPrefill(PaymentDirection::OUTBOUND);

        if ($prefill !== []) {
            $this->form->fill($prefill);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingAllocations = $data['allocations'] ?? [];
        $this->pendingCreditApplications = $data['credit_applications'] ?? [];

        $data = $this->extractBankFee($data);

        $data['amount'] = Money::toMinor((float) $data['amount']);
        $data['status'] = PaymentStatus::PENDING_APPROVAL->value;
        $data['direction'] = PaymentDirection::OUTBOUND->value;

        unset($data['allocations'], $data['credit_applications']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->persistAllocations($this->record, $this->record->currency_code);
        $this->persistCreditApplications($this->record);
        $this->persistBankFee($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
