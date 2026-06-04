<?php

namespace App\Filament\Resources\Finance\Trips\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Travel\Enums\TripStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TripsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('start_date')
                    ->label(__('forms.labels.period'))
                    ->formatStateUsing(fn ($state, $record) => $record->end_date
                        ? $state->format('d/m/Y').' – '.$record->end_date->format('d/m/Y')
                        : $state->format('d/m/Y'))
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('forms.labels.trip_title'))
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->title),

                TextColumn::make('company_label')
                    ->label(__('forms.labels.company'))
                    ->state(fn ($record) => $record->company_label)
                    ->badge()
                    ->color(fn ($record) => $record->is_internal ? 'gray' : 'primary'),

                TextColumn::make('destination_city')
                    ->label(__('forms.labels.destination'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('traveler.name')
                    ->label(__('forms.labels.traveler'))
                    ->placeholder('—'),

                TextColumn::make('total')
                    ->label(__('forms.labels.total'))
                    ->state(fn ($record) => collect($record->totals_by_currency)
                        ->map(fn ($amount, $code) => Money::format($amount).' '.$code)
                        ->implode(' · ') ?: '—')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('start_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(TripStatus::class),

                Filter::make('internal')
                    ->label(__('forms.labels.internal_expense'))
                    ->query(fn (Builder $query) => $query->where('is_internal', true))
                    ->toggle(),
            ])
            ->recordActions([
                // Approval/rejection live on the trip detail page (ViewTrip),
                // where the billing currency + FX rate are chosen.
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
