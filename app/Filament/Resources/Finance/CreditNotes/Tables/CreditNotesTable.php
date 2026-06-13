<?php

namespace App\Filament\Resources\Finance\CreditNotes\Tables;

use App\Domain\Financial\Enums\CreditNoteParty;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('party_type')
                    ->label(__('forms.labels.party'))
                    ->badge(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.company'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('consumed_amount')
                    ->label(__('forms.labels.applied_amount'))
                    ->state(fn ($record) => $record->consumed_amount)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($record) => $record->consumed_amount >= (int) $record->total_amount && (int) $record->total_amount > 0 ? 'success' : 'gray')
                    ->alignEnd(),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining_amount'))
                    ->state(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'success')
                    ->alignEnd(),
                TextColumn::make('line_items_count')
                    ->label(__('forms.labels.lines'))
                    ->counts('lineItems')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label(__('forms.labels.issued'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(CreditNoteStatus::class),
                SelectFilter::make('party_type')
                    ->label(__('forms.labels.party'))
                    ->options(CreditNoteParty::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
