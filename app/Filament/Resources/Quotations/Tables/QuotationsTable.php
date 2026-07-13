<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Filament\Actions\StatusTransitionActions;
use Filament\Actions\ActionGroup;
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

class QuotationsTable
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
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->searchable()
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->description)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('version')
                    ->label(__('forms.labels.version'))
                    ->prefix('v')
                    ->alignCenter(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('commission_type')
                    ->label(__('forms.labels.commission'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_converted_cost')
                    ->label(__('forms.labels.total_cost'))
                    ->getStateUsing(fn ($record) => $record->total_converted_cost)
                    ->money(fn ($record) => $record->currency_code, divideBy: 10000)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('avg_margin')
                    ->label(__('forms.labels.margin'))
                    ->getStateUsing(function ($record) {
                        $cost = $record->total_converted_cost;
                        if ($cost <= 0) {
                            return null;
                        }

                        return round((($record->subtotal - $cost) / $cost) * 100, 2);
                    })
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 1).'%')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state < 10 => 'danger',
                        $state < 25 => 'warning',
                        default => 'success',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('valid_until')
                    ->label(__('forms.labels.valid_until'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
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
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::class),
                SelectFilter::make('commission_type')
                    ->label(__('forms.labels.commission_model'))
                    ->options(CommissionType::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.client'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                Filter::make('has_multi_currency_items')
                    ->label(__('forms.filters.has_multi_currency_items'))
                    ->query(fn ($query) => $query->whereHas('items', function ($q) {
                        $q->whereNotNull('cost_currency_code')
                            ->whereColumn('cost_currency_code', '!=', 'quotations.currency_code');
                    })),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    StatusTransitionActions::make(QuotationStatus::class),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No quotations')
            ->emptyStateDescription('Create your first quotation to start quoting clients.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
