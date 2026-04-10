<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDebitNote extends CreateRecord
{
    protected static string $resource = DebitNoteResource::class;

    protected array $pendingLineItems = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingLineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        $data['status'] = DebitNoteStatus::DRAFT->value;
        $data['total_amount'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        $debitNote = $this->record;
        $total = 0;

        foreach ($this->pendingLineItems as $item) {
            $amount = Money::toMinor((float) ($item['amount'] ?? 0));
            $total += $amount;

            DebitNoteLineItem::create([
                'debit_note_id' => $debitNote->id,
                'description' => $item['description'] ?? '',
                'amount' => $amount,
                'currency_code' => $item['currency_code'] ?? $debitNote->currency_code,
            ]);
        }

        $debitNote->update(['total_amount' => $total]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
