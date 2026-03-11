<?php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\RelationManagers;

use App\Domain\Planning\Actions\UpdatePaymentScheduleFromProductionAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Production Entries';

    public function canCreate(): bool
    {
        return false;
    }

    public function canDeleteRecords(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('actual_quantity')
                ->label('Quantity Produced')
                ->numeric()
                ->minValue(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proformaInvoiceItem.product.name')
                    ->label('Product')
                    ->default(fn ($record) => $record->proformaInvoiceItem?->description ?? '—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label('Production Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Planned')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('actual_quantity')
                    ->label('Actual')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),
            ])
            ->defaultSort('production_date', 'asc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('supplier-portal:update-production-actuals') ?? false)
                    ->after(function ($record) {
                        $schedule = $record->productionSchedule;
                        app(UpdatePaymentScheduleFromProductionAction::class)->execute($schedule);
                    }),
            ])
            ->emptyStateHeading('No production entries')
            ->emptyStateDescription('No production entries have been recorded yet.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
