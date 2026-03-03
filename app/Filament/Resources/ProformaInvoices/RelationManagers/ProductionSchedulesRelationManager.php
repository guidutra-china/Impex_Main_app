<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ProductionSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'productionSchedules';

    protected static ?string $title = 'Production Schedules';

    protected static BackedEnum|string|null $icon = 'heroicon-o-calendar-days';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('version')
                    ->label(__('forms.labels.version'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('entries_count')
                    ->label(__('forms.labels.entries'))
                    ->counts('entries')
                    ->alignCenter(),
                TextColumn::make('received_date')
                    ->label(__('forms.labels.received_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.admin.resources.production-schedules.view', $record)),
            ])
            ->headerActions([
                Action::make('create_production_schedule')
                    ->label('New Production Schedule')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->can('create-production-schedules'))
                    ->url(fn () => route('filament.admin.resources.production-schedules.create', [
                        'proforma_invoice_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->emptyStateHeading('No production schedules')
            ->emptyStateDescription('Create a production schedule to track supplier manufacturing progress for this PI.')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->defaultSort('created_at', 'desc');
    }
}
