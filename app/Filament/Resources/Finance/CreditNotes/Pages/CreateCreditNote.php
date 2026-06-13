<?php

namespace App\Filament\Resources\Finance\CreditNotes\Pages;

use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCreditNote extends CreateRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected array $pendingLineItems = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingLineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        $data['status'] = CreditNoteStatus::DRAFT->value;
        $data['total_amount'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        $creditNote = $this->record;
        $total = 0;

        foreach ($this->pendingLineItems as $item) {
            $amount = Money::toMinor((float) ($item['amount'] ?? 0));
            $total += $amount;

            CreditNoteLineItem::create([
                'credit_note_id' => $creditNote->id,
                'description' => $item['description'] ?? '',
                'amount' => $amount,
                'currency_code' => $item['currency_code'] ?? $creditNote->currency_code,
            ]);
        }

        $creditNote->update(['total_amount' => $total]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
