<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DebitNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'debitNotes';

    protected static ?string $title = 'Debit Notes';

    protected static BackedEnum|string|null $icon = 'heroicon-o-document-text';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => DebitNoteResource::getUrl('view', ['record' => $record]))
                    ->color('primary'),
                TextColumn::make('total_amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('lineItems_count')
                    ->label(__('forms.labels.lines'))
                    ->counts('lineItems')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label(__('forms.labels.issued'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('createDebitNote')
                    ->label(__('forms.labels.create_debit_note'))
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->url(fn () => DebitNoteResource::getUrl('create', [
                        'company_id' => $this->getOwnerRecord()->company_id,
                        'proforma_invoice_id' => $this->getOwnerRecord()->id,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }
}
