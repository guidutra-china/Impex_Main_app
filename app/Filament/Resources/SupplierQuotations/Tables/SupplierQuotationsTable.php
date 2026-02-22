<?php

namespace App\Filament\Resources\SupplierQuotations\Tables;

use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
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
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('inquiry.reference')
                    ->label('Inquiry')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->inquiry
                        ? route('filament.panel.resources.inquiries.view', $record->inquiry)
                        : null
                    ),
                TextColumn::make('company.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_reference')
                    ->label('Supplier Ref.')
                    ->searchable()
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('Pending'),
                TextColumn::make('valid_until')
                    ->label('Valid Until')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SupplierQuotationStatus::class)
                    ->multiple(),
                SelectFilter::make('inquiry_id')
                    ->label('Inquiry')
                    ->relationship('inquiry', 'reference')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('company_id')
                    ->label('Supplier')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
