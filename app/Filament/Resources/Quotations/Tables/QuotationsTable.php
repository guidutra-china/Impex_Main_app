<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('company.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('version')
                    ->label('Version')
                    ->prefix('v')
                    ->alignCenter(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('commission_type')
                    ->label('Commission')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('valid_until')
                    ->label('Valid Until')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::class),
                SelectFilter::make('commission_type')
                    ->label('Commission Model')
                    ->options(CommissionType::class),
                SelectFilter::make('company_id')
                    ->label('Client')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No quotations')
            ->emptyStateDescription('Create your first quotation to start quoting clients.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
