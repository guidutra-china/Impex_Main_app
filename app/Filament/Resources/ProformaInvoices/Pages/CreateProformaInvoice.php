<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Inquiries\Actions\AdvanceInquiryToQuotedAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProformaInvoice extends CreateRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        app(AdvanceInquiryToQuotedAction::class)->execute($this->record->inquiry);
    }
}
