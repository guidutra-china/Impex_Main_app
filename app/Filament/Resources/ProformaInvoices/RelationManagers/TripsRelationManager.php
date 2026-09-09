<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\Trips\TripResource;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Viagens vinculadas a esta PI (trips.proforma_invoice_id). Só leitura: a
 * viagem é criada e editada no módulo de Viagens; aqui é o rastro.
 */
class TripsRelationManager extends RelationManager
{
    protected static string $relationship = 'trips';

    protected static BackedEnum|string|null $icon = 'heroicon-o-paper-airplane';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('forms.labels.trips');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('start_date')
                    ->label(__('forms.labels.start_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('forms.labels.trip_title'))
                    ->weight('bold')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->title),
                TextColumn::make('traveler.name')
                    ->label(__('forms.labels.traveler'))
                    ->placeholder('—'),
                TextColumn::make('destination_city')
                    ->label(__('forms.labels.destination'))
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label(__('forms.labels.total'))
                    ->state(fn ($record) => collect($record->totals_by_currency)
                        ->map(fn ($amount, $code) => Money::format($amount).' '.$code)
                        ->implode(' · ') ?: '—')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => TripResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading(__('forms.labels.no_trips_linked'))
            ->emptyStateIcon('heroicon-o-paper-airplane');
    }
}
