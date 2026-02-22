<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class InquiriesTable
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
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('deadline')
                    ->label('Deadline')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->deadline && $record->deadline->isPast() ? 'danger' : null),
                TextColumn::make('quotations_count')
                    ->label('Quotations')
                    ->counts('quotations')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InquiryStatus::class),
                SelectFilter::make('source')
                    ->options(InquirySource::class),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No inquiries')
            ->emptyStateDescription('Register your first client inquiry to start the quotation process.')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down');
    }
}
