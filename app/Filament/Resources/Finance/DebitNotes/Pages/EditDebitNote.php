<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDebitNote extends EditRecord
{
    protected static string $resource = DebitNoteResource::class;

    protected array $pendingLineItems = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['line_items'] = $this->record->lineItems->map(fn ($item) => [
            'description' => $item->description,
            'amount' => Money::toMajor($item->amount),
            'currency_code' => $item->currency_code,
        ])->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingLineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        return $data;
    }

    protected function afterSave(): void
    {
        $debitNote = $this->record;

        $debitNote->lineItems()->delete();

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

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
