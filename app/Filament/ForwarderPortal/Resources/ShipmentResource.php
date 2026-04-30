<?php

namespace App\Filament\ForwarderPortal\Resources;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\ForwarderPortal\Resources\ShipmentResource\Pages;
use App\Filament\ForwarderPortal\Resources\ShipmentResource\RelationManagers\ForwarderAdditionalCostsRelationManager;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'shipments';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('forwarder-portal:view-shipments') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make(__('forwarder_portal.shipment.section.read_only'))
                ->schema([
                    TextInput::make('reference')->disabled(),
                    TextInput::make('bl_number')->label('B/L')->disabled(),
                    TextInput::make('booking_number')->disabled(),
                    TextInput::make('container_number')->disabled(),
                    TextInput::make('vessel_name')->disabled(),
                    TextInput::make('voyage_number')->disabled(),
                    TextInput::make('origin_port')->disabled(),
                    TextInput::make('destination_port')->disabled(),
                ])
                ->columns(2),

            FormSection::make(__('forwarder_portal.shipment.section.editable'))
                ->description(__('forwarder_portal.shipment.section.editable_desc'))
                ->schema([
                    DatePicker::make('etd')
                        ->label(__('forwarder_portal.shipment.etd'))
                        ->native(false),
                    DatePicker::make('eta')
                        ->label(__('forwarder_portal.shipment.eta'))
                        ->native(false)
                        ->helperText(__('forwarder_portal.shipment.eta_helper')),
                    Select::make('status')
                        ->label(__('forwarder_portal.shipment.status'))
                        ->options(fn ($record) => static::editableStatusOptions($record))
                        ->disabled(fn ($record) => empty(static::editableStatusOptions($record)))
                        ->helperText(__('forwarder_portal.shipment.status_helper'))
                        ->live()
                        ->required(),
                    DatePicker::make('actual_departure')
                        ->label(__('forwarder_portal.shipment.actual_departure'))
                        ->native(false),
                    DatePicker::make('actual_arrival')
                        ->label(__('forwarder_portal.shipment.actual_arrival'))
                        ->native(false),
                    Toggle::make('set_actual_arrival_today')
                        ->label(__('forwarder_portal.shipment.set_actual_arrival_today'))
                        ->helperText(__('forwarder_portal.shipment.set_actual_arrival_today_helper'))
                        ->dehydrated(false)
                        ->default(true)
                        ->visible(function (Get $get, ?Shipment $record) {
                            $newStatus = $get('status');
                            $previousStatus = $record?->status?->value;

                            return $newStatus === ShipmentStatus::ARRIVED->value
                                && $previousStatus !== ShipmentStatus::ARRIVED->value;
                        }),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Forwarder may only set IN_TRANSIT or ARRIVED, and only when the
     * existing state machine permits it. Current status is always included
     * so the select doesn't appear empty when the value is unchanged.
     */
    protected static function editableStatusOptions($record): array
    {
        if (! $record) {
            return [];
        }

        $allowedForForwarder = [
            ShipmentStatus::IN_TRANSIT->value,
            ShipmentStatus::ARRIVED->value,
        ];

        $current = $record->status?->value;
        $candidates = collect(Shipment::allowedTransitions()[$current] ?? [])
            ->intersect($allowedForForwarder)
            ->values()
            ->all();

        $options = [];
        if ($current) {
            $options[$current] = ShipmentStatus::from($current)->getLabel();
        }
        foreach ($candidates as $value) {
            $options[$value] = ShipmentStatus::from($value)->getLabel();
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $tenant = Filament::getTenant();
                if ($tenant) {
                    $query->forForwarderCompany($tenant->getKey());
                }
            })
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('bl_number')
                    ->label('B/L')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('origin_port')->label(__('forwarder_portal.shipment.origin'))->placeholder('—'),
                TextColumn::make('destination_port')->label(__('forwarder_portal.shipment.destination'))->placeholder('—'),
                TextColumn::make('etd')->label('ETD')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('eta')->label('ETA')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('vessel_name')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('container_number')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ShipmentStatus::class),
            ])
            ->recordUrl(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record])),
                \Filament\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false)
                    ->url(fn (Shipment $record) => Pages\EditShipment::getUrl(['record' => $record])),
            ])
            ->defaultSort('eta', 'asc')
            ->emptyStateHeading(__('forwarder_portal.shipment.empty_heading'))
            ->emptyStateDescription(__('forwarder_portal.shipment.empty_desc'))
            ->emptyStateIcon('heroicon-o-truck');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forwarder_portal.shipment.section.summary'))
                ->schema([
                    TextEntry::make('reference')->copyable()->weight('bold'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('bl_number')->label('B/L')->placeholder('—'),
                    TextEntry::make('booking_number')->placeholder('—'),
                    TextEntry::make('forwarderCompany.name')->label(__('forwarder_portal.shipment.forwarder'))->placeholder('—'),
                    TextEntry::make('responsible.name')->label(__('forwarder_portal.shipment.responsible'))->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forwarder_portal.shipment.section.route'))
                ->schema([
                    TextEntry::make('origin_port')->placeholder('—'),
                    TextEntry::make('destination_port')->placeholder('—'),
                    TextEntry::make('vessel_name')->placeholder('—'),
                    TextEntry::make('voyage_number')->placeholder('—'),
                    TextEntry::make('container_number')->placeholder('—'),
                    TextEntry::make('container_type')->placeholder('—'),
                    TextEntry::make('etd')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('eta')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('actual_departure')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('actual_arrival')->date('d/m/Y')->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            ForwarderAdditionalCostsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            'view' => Pages\ViewShipment::route('/{record}'),
            'edit' => Pages\EditShipment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.shipments');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.shipment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.shipments');
    }
}
