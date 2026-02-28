<?php

namespace App\Filament\Resources\SupplierQuotations\Tables;

use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Filament\Actions\StatusTransitionActions;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierQuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('inquiry.reference')
                    ->label(__('forms.labels.inquiry'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->inquiry
                        ? InquiryResource::getUrl('view', ['record' => $record->inquiry])
                        : null
                    ),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_reference')
                    ->label(__('forms.labels.supplier_ref'))
                    ->searchable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lead_time_days')
                    ->label(__('forms.labels.lead_time'))
                    ->suffix(' days')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('requested_at')
                    ->label(__('forms.labels.requested'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label(__('forms.labels.received'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder(__('forms.placeholders.pending')),
                TextColumn::make('valid_until')
                    ->label(__('forms.labels.valid_until'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SupplierQuotationStatus::class)
                    ->multiple(),
                SelectFilter::make('inquiry_id')
                    ->label(__('forms.labels.inquiry'))
                    ->relationship('inquiry', 'reference')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.supplier'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                StatusTransitionActions::make(SupplierQuotationStatus::class),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
