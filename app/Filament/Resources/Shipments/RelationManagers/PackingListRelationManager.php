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
                ->label(__('forms.labels.product'))
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
                ->placeholder(__('forms.placeholders.additional_description_if_needed')),

            Section::make(__('forms.sections.packaging_container'))
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('packaging_type')
                            ->label(__('forms.labels.packaging_type'))
                            ->options(PackagingType::class)
                            ->default(PackagingType::CARTON->value)
                            ->required()
                            ->helperText(__('forms.helpers.autofilled_from_product_packaging')),

                        TextInput::make('container_number')
                            ->label(__('forms.labels.container_2'))
                            ->maxLength(50)
                            ->placeholder(__('forms.placeholders.eg_cclu7730065')),

                        TextInput::make('pallet_number')
                            ->label(__('forms.labels.pallet_2'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder(__('forms.placeholders.leave_empty_if_not_palletized')),
                    ]),
                ]),

            Section::make(__('forms.sections.quantity_cartons'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('total_quantity')
                            ->label(__('forms.labels.total_pieces'))
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->recalculateFromQuantity($get, $set)),

                        TextInput::make('qty_per_carton')
                            ->label(__('forms.labels.qty_per_package'))
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->recalculateFromQuantity($get, $set))
                            ->helperText(__('forms.helpers.autofilled_from_product_packaging')),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('quantity')
                            ->label(__('forms.labels.number_of_packages'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set))
                            ->helperText(__('forms.helpers.autocalculated_editable_for_mixed_cartons')),

                        TextInput::make('carton_from')
                            ->label(__('forms.labels.package_from'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText(__('forms.helpers.autocalculated_editable_for_mixed_cartons')),

                        TextInput::make('carton_to')
                            ->label(__('forms.labels.package_to'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText(__('forms.helpers.autocalculated_editable_for_mixed_cartons')),
                    ]),
                ]),

            Section::make(__('forms.sections.weight_dimensions_per_package'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('gross_weight')
                            ->label(__('forms.labels.gross_weight_kg'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),

                        TextInput::make('net_weight')
                            ->label(__('forms.labels.net_weight_kg'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),
                    ]),

                    Grid::make(4)->schema([
                        TextInput::make('length')
                            ->label(__('forms.labels.l_cm'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('width')
                            ->label(__('forms.labels.w_cm'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('height')
                            ->label(__('forms.labels.h_cm'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

                        TextInput::make('volume')
                            ->label(__('forms.labels.cbmpkg'))
                            ->numeric()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateWeightVolumeTotals($get, $set)),
                    ]),
                ])
                ->collapsible(),

            Section::make(__('forms.sections.totals'))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('total_gross_weight')
                            ->label(__('forms.labels.total_gw_kg'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),

                        TextInput::make('total_net_weight')
                            ->label(__('forms.labels.total_nw_kg'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),

                        TextInput::make('total_volume')
                            ->label(__('forms.labels.total_cbm'))
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
                    ->label(__('forms.labels.container'))
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('pallet_number')
                    ->label(__('forms.labels.pallet'))
                    ->formatStateUsing(fn ($state) => $state ? 'PLT-' . str_pad($state, 2, '0', STR_PAD_LEFT) : '—')
                    ->badge()
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('packaging_type')
                    ->label(__('forms.labels.type'))
                    ->badge(),
                TextColumn::make('carton_range')
                    ->label(__('forms.labels.packages'))
                    ->weight('bold'),
                TextColumn::make('product_name')
                    ->label(__('forms.labels.product'))
                    ->limit(30),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.pkgs'))
                    ->alignCenter()
                    ->summarize(Sum::make()->label(__('forms.labels.total'))),
                TextColumn::make('qty_per_carton')
                    ->label(__('forms.labels.pcspkg'))
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('total_quantity')
                    ->label(__('forms.labels.total_pcs'))
                    ->alignCenter()
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))),
                TextColumn::make('total_gross_weight')
                    ->label(__('forms.labels.total_gw_kg'))
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))->suffix(' kg')),
                TextColumn::make('total_net_weight')
                    ->label(__('forms.labels.total_nw_kg'))
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))->suffix(' kg'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_volume')
                    ->label(__('forms.labels.total_cbm'))
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))->suffix(' CBM')),
                TextColumn::make('description')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('container_number')
                    ->label(__('forms.labels.container'))
                    ->getTitleFromRecordUsing(fn ($record) => $record->container_number
                        ? 'Container: ' . $record->container_number
                        : 'No Container'),
                Group::make('pallet_number')
                    ->label(__('forms.labels.pallet'))
                    ->getTitleFromRecordUsing(fn ($record) => $record->pallet_number
                        ? 'Pallet ' . str_pad($record->pallet_number, 2, '0', STR_PAD_LEFT)
                        : 'No Pallet'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('generate_packing_list')
                    ->label(__('forms.labels.generate_from_items'))
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate Packing List')
                    ->modalDescription('This will delete all existing packing list entries and regenerate them from shipment items using product packaging data. Continue?')
                    ->modalSubmitActionLabel('Generate')
                    ->visible(fn () => $this->getOwnerRecord()->items()->count() > 0 && auth()->user()?->can('edit-shipments'))
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
                    ->label(__('forms.labels.add_line'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
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
