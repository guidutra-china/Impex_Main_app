<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Domain\Financial\Actions\SyncDebitNoteScheduleAction;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDebitNote extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = DebitNoteResource::class;

    protected array $pendingLineItems = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['line_items'] = $this->record->lineItems->map(fn ($item) => [
            'id' => $item->id,
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

        $this->ensureRemovedLinesAreNotAllocated();

        return $data;
    }

    /**
     * A removed line whose schedule item already carries payment allocations
     * would orphan those allocations — block the save instead.
     */
    protected function ensureRemovedLinesAreNotAllocated(): void
    {
        $keptIds = collect($this->pendingLineItems)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        $removedIds = $this->record->lineItems()
            ->whereNotIn('id', $keptIds)
            ->pluck('id');

        if ($removedIds->isEmpty()) {
            return;
        }

        $allocated = PaymentScheduleItem::query()
            ->where('source_type', DebitNoteLineItem::class)
            ->whereIn('source_id', $removedIds)
            ->where(fn ($q) => $q->whereHas('allocations')->orWhereHas('creditAllocations'))
            ->exists();

        if ($allocated) {
            throw ValidationException::withMessages([
                'data.line_items' => __('forms.validation.line_has_allocations_cannot_remove'),
            ]);
        }
    }

    protected function afterSave(): void
    {
        $debitNote = $this->record;

        $total = 0;
        $keptIds = [];

        foreach ($this->pendingLineItems as $item) {
            $amount = Money::toMinor((float) ($item['amount'] ?? 0));
            $total += $amount;

            $attributes = [
                'description' => $item['description'] ?? '',
                'amount' => $amount,
                'currency_code' => $item['currency_code'] ?? $debitNote->currency_code,
            ];

            $existing = ! empty($item['id'])
                ? $debitNote->lineItems()->find($item['id'])
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;
            } else {
                $keptIds[] = DebitNoteLineItem::create(
                    ['debit_note_id' => $debitNote->id] + $attributes,
                )->id;
            }
        }

        $debitNote->lineItems()->whereNotIn('id', $keptIds)->delete();

        $debitNote->update(['total_amount' => $total]);

        app(SyncDebitNoteScheduleAction::class)->execute($debitNote->refresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT),
        ];
    }
}
