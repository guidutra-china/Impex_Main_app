<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Logistics\Actions\GeneratePackingListAction;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\ShipmentItem;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use UnitEnum;

class PackingListRelationManager extends RelationManager
{
    protected static string $relationship = 'packingListItems';

    protected static ?string $title = 'Packing List';

    protected static BackedEnum|string|null $icon = 'heroicon-o-archive-box';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('shipment_item_id')
                ->label('Product')
                ->options(function () {
                    $shipment = $this->getOwnerRecord();

                    $packedQtyByItem = $shipment->packingListItems()
                        ->reorder()
                        ->selectRaw('shipment_item_id, SUM(total_quantity) as packed_qty')
                        ->groupBy('shipment_item_id')
                        ->pluck('packed_qty', 'shipment_item_id');

                    return $shipment
                        ->items()
                        ->with('proformaInvoiceItem.product')
                        ->get()
                        ->mapWithKeys(function ($item) use ($packedQtyByItem) {
                            $packed = (int) ($packedQtyByItem[$item->id] ?? 0);
                            $remaining = $item->quantity - $packed;
                            $label = $item->product_name . ' (Remaining: ' . $remaining . ' / ' . $item->quantity . ')';

                            return [$item->id => $label];
                        });
                })
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if (! $state) {
                        return;
                    }

                    $shipmentItem = ShipmentItem::with('proformaInvoiceItem.product.packaging')->find($state);
                    $packaging = $shipmentItem?->proformaInvoiceItem?->product?->packaging;

                    if (! $packaging) {
                        return;
                    }

                    $set('packaging_type', $packaging->packaging_type?->value ?? PackagingType::CARTON->value);

                    if ($packaging->pcs_per_carton > 0) {
                        $set('qty_per_carton', $packaging->pcs_per_carton);
                    }
                    if ($packaging->carton_weight > 0) {
                        $set('gross_weight', (float) $packaging->carton_weight);
                    }

                    if ($packaging->carton_net_weight > 0) {
                        $set('net_weight', (float) $packaging->carton_net_weight);
                    }

                    if ($packaging->carton_length > 0) {
                        $set('length', (float) $packaging->carton_length);
                    }
                    if ($packaging->carton_width > 0) {
                        $set('width', (float) $packaging->carton_width);
                    }
                    if ($packaging->carton_height > 0) {
                        $set('height', (float) $packaging->carton_height);
                    }
                    if ($packaging->carton_cbm > 0) {
                        $set('volume', (float) $packaging->carton_cbm);
                    }
                }),

            TextInput::make('description')
                ->maxLength(255)
                ->placeholder('Additional description if needed'),

            Section::make('Packaging & Container')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('packaging_type')
                            ->label('Packaging Type')
                            ->options(PackagingType::class)
                            ->default(PackagingType::CARTON->value)
                            ->required()
                            ->helperText('Auto-filled from product packaging'),

                        TextInput::make('container_number')
                            ->label('Container #')
                            ->maxLength(50)
                            ->placeholder('e.g. CCLU7730065'),

                        TextInput::make('pallet_number')
                            ->label('Pallet #')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('Leave empty if not palletized'),
                    ]),
                ]),

            Section::make('Quantity & Cartons')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('total_quantity')
                            ->label('Total Pieces')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->recalculateFromQuantity($get, $set)),

                        TextInput::make('qty_per_carton')
                            ->label('Qty per Package')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->recalculateFromQuantity($get, $set))
                            ->helperText('Auto-filled from product packaging'),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('quantity')
                            ->label('Number of Packages')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set))
                            ->helperText('Auto-calculated, editable for mixed cartons'),

                        TextInput::make('carton_from')
                            ->label('Package From')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Auto-calculated, editable for mixed cartons'),

                        TextInput::make('carton_to')
                            ->label('Package To')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Auto-calculated, editable for mixed cartons'),
                    ]),
                ]),

            Section::make('Weight & Dimensions (per package)')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('gross_weight')
                            ->label('Gross Weight (kg)')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),

                        TextInput::make('net_weight')
                            ->label('Net Weight (kg)')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),
                    ]),

                    Grid::make(4)->schema([
                        TextInput::make('length')
                            ->label('L (cm)')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('width')
                            ->label('W (cm)')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('height')
                            ->label('H (cm)')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('volume')
                            ->label('CBM/Pkg')
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),
                    ]),
                ])
                ->collapsible(),

            Section::make('Totals')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('total_gross_weight')
                            ->label('Total GW (kg)')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),

                        TextInput::make('total_net_weight')
                            ->label('Total NW (kg)')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),

                        TextInput::make('total_volume')
                            ->label('Total CBM')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),
                    ]),
                ]),

            Textarea::make('notes')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('container_number')
                    ->label('Container')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('pallet_number')
                    ->label('Pallet')
                    ->formatStateUsing(fn ($state) => $state ? 'PLT-' . str_pad($state, 2, '0', STR_PAD_LEFT) : '—')
                    ->badge()
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('packaging_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('carton_range')
                    ->label('Packages')
                    ->weight('bold'),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->limit(30),
                TextColumn::make('quantity')
                    ->label('# Pkgs')
                    ->alignCenter()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('qty_per_carton')
                    ->label('Pcs/Pkg')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('total_quantity')
                    ->label('Total Pcs')
                    ->alignCenter()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('total_gross_weight')
                    ->label('Total GW (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg')),
                TextColumn::make('total_net_weight')
                    ->label('Total NW (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_volume')
                    ->label('Total CBM')
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')->suffix(' CBM')),
                TextColumn::make('description')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('container_number')
                    ->label('Container')
                    ->getTitleFromRecordUsing(fn ($record) => $record->container_number
                        ? 'Container: ' . $record->container_number
                        : 'No Container'),
                Group::make('pallet_number')
                    ->label('Pallet')
                    ->getTitleFromRecordUsing(fn ($record) => $record->pallet_number
                        ? 'Pallet ' . str_pad($record->pallet_number, 2, '0', STR_PAD_LEFT)
                        : 'No Pallet'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
                \Filament\Actions\DeleteAction::make()
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('generate_packing_list')
                    ->label('Generate from Items')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate Packing List')
                    ->modalDescription('This will delete all existing packing list entries and regenerate them from shipment items using product packaging data. Continue?')
                    ->modalSubmitActionLabel('Generate')
                    ->visible(fn () => $this->getOwnerRecord()->items()->count() > 0)
                    ->action(function () {
                        $shipment = $this->getOwnerRecord();
                        $count = app(GeneratePackingListAction::class)->execute($shipment);

                        Notification::make()
                            ->success()
                            ->title('Packing list generated')
                            ->body("{$count} line(s) created from shipment items.")
                            ->send();
                    }),
                \Filament\Actions\CreateAction::make()
                    ->label('Add Line')
                    ->icon('heroicon-o-plus')
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
            ])
            ->emptyStateHeading('No packing details')
            ->emptyStateDescription('Use "Generate from Items" to auto-create from product packaging data, or add lines manually.')
            ->emptyStateIcon('heroicon-o-archive-box')
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    protected function getNextCartonStart(): int
    {
        $maxCartonTo = $this->getOwnerRecord()
            ->packingListItems()
            ->max('carton_to');

        return ($maxCartonTo ?? 0) + 1;
    }

    protected function recalculateFromQuantity(Get $get, Set $set): void
    {
        $totalQty = (int) $get('total_quantity');
        $qtyPerCarton = (int) $get('qty_per_carton');

        if ($totalQty <= 0 || $qtyPerCarton <= 0) {
            $set('quantity', null);
            $set('carton_from', null);
            $set('carton_to', null);
            $set('total_gross_weight', null);
            $set('total_net_weight', null);
            $set('total_volume', null);
            return;
        }

        $numCartons = (int) ceil($totalQty / $qtyPerCarton);
        $set('quantity', $numCartons);

        $cartonFrom = $this->getNextCartonStart();
        $cartonTo = $cartonFrom + $numCartons - 1;
        $set('carton_from', $cartonFrom);
        $set('carton_to', $cartonTo);

        static::recalculateWeightVolumeTotals($get, $set, $numCartons);
    }

    protected static function recalculateWeightVolumeTotals(Get $get, Set $set, ?int $numCartons = null): void
    {
        if ($numCartons === null) {
            $numCartons = (int) $get('quantity');
        }

        $grossWeight = (float) $get('gross_weight');
        $netWeight = (float) $get('net_weight');
        $volume = (float) $get('volume');

        $set('total_gross_weight', ($numCartons && $grossWeight) ? round($grossWeight * $numCartons, 3) : null);
        $set('total_net_weight', ($numCartons && $netWeight) ? round($netWeight * $numCartons, 3) : null);
        $set('total_volume', ($numCartons && $volume) ? round($volume * $numCartons, 4) : null);
    }

    protected static function calculateVolume(Get $get, Set $set): void
    {
        $length = (float) $get('length');
        $width = (float) $get('width');
        $height = (float) $get('height');

        if ($length && $width && $height) {
            $volume = round(($length * $width * $height) / 1000000, 4);
            $set('volume', $volume);
        }

        static::recalculateWeightVolumeTotals($get, $set);
    }
}
