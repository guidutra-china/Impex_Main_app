<?php

namespace App\Filament\Resources\Finance\AccountsReceivable\Pages;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountsReceivable extends CreateRecord
{
    use HasPaymentAllocationPersistence;

    protected static string $resource = AccountsReceivableResource::class;

    public function mount(): void
    {
        parent::mount();

        $companyId = request()->query('company_id');

        if ($companyId) {
            $this->form->fill([
                'direction' => PaymentDirection::INBOUND->value,
                'company_id' => (int) $companyId,
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingAllocations = $data['allocations'] ?? [];
        $this->pendingCreditApplications = $data['credit_applications'] ?? [];

        $data['amount'] = Money::toMinor((float) $data['amount']);
        $data['status'] = PaymentStatus::PENDING_APPROVAL->value;
        $data['direction'] = PaymentDirection::INBOUND->value;

        unset($data['allocations'], $data['credit_applications']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->persistAllocations($this->record, $this->record->currency_code);
        $this->persistCreditApplications($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
