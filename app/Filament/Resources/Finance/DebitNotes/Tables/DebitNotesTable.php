<?php

namespace App\Filament\Resources\Finance\DebitNotes\Tables;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DebitNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('proformaInvoice.reference')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->placeholder('—'),
                TextColumn::make('total_amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('line_items_count')
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
                    ->options(DebitNoteStatus::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.client'))
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
