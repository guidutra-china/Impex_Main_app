<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Filament\Actions\StatusTransitionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('source')
                    ->label(__('forms.labels.source'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('received_at')
                    ->label(__('forms.labels.received'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('deadline')
                    ->label(__('forms.labels.deadline'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->deadline && $record->deadline->isPast() ? 'danger' : null),
                TextColumn::make('quotations_count')
                    ->label(__('forms.labels.quotations'))
                    ->counts('quotations')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('responsible.name')
                    ->label(__('forms.labels.responsible'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
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
                    ->label(__('forms.labels.client'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                TrashedFilter::make(),
            ])
            ->recordActions([
                StatusTransitionActions::make(InquiryStatus::class),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No inquiries')
            ->emptyStateDescription('Register your first client inquiry to start the quotation process.')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down');
    }
}
