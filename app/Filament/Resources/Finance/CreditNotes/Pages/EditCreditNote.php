<?php

namespace App\Filament\Resources\Finance\CreditNotes\Pages;

use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\CreditNotes\CreditNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCreditNote extends EditRecord
{
    protected static string $resource = CreditNoteResource::class;

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
        $creditNote = $this->record;

        $creditNote->lineItems()->delete();

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

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => $this->record->status === CreditNoteStatus::DRAFT),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
