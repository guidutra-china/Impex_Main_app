<?php

namespace App\Filament\SupplierPortal\Resources;

use App\Domain\Planning\Models\ProductionSchedule;
use App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;
use App\Filament\SupplierPortal\Resources\ProductionScheduleResource\RelationManagers\EntriesRelationManager;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductionScheduleResource extends Resource
{
    protected static ?string $model = ProductionSchedule::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 42;
    protected static ?string $slug = 'production-schedules';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'supplierCompany';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('supplier-portal:view-production-schedules') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proformaInvoice.reference')
                    ->label('PI Reference')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('received_date')
                    ->label('Received Date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('entries_count')
                    ->label('Entries')
                    ->counts('entries')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (ProductionSchedule $record) => Pages\ViewProductionSchedule::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (ProductionSchedule $record) => Pages\ViewProductionSchedule::getUrl(['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No production schedules')
            ->emptyStateDescription('No production schedules found for your company.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Production Schedule Details'))
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('proformaInvoice.reference')
                        ->label('PI Reference')
                        ->placeholder('—'),
                    TextEntry::make('received_date')
                        ->label('Received Date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('version')
                        ->label('Version'),
                    TextEntry::make('notes')
                        ->label('Notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionSchedules::route('/'),
            'view' => Pages\ViewProductionSchedule::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.production_schedules', [], null) ?? 'Production Schedules';
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.production_schedule', [], null) ?? 'Production Schedule';
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.production_schedules', [], null) ?? 'Production Schedules';
    }
}
