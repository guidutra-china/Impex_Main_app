<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Logistics\Actions\GeneratePackingListAction;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\ShipmentItem;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
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
                    return $this->getOwnerRecord()
                        ->items()
                        ->with('proformaInvoiceItem.product')
                        ->get()
                        ->mapWithKeys(fn ($item) => [
                            $item->id => $item->product_name . ' (Qty: ' . $item->quantity . ')',
                        ]);
                })
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if (! $state) {
                        return;
                    }

                    $shipmentItem = ShipmentItem::with('proformaInvoiceItem.product.packaging', 'proformaInvoiceItem.product.specification')->find($state);
                    $packaging = $shipmentItem?->proformaInvoiceItem?->product?->packaging;

                    if (! $packaging) {
                        return;
                    }

                    if ($packaging->pcs_per_carton > 0) {
                        $set('qty_per_carton', $packaging->pcs_per_carton);
                    }
                    if ($packaging->carton_weight > 0) {
                        $set('gross_weight', (float) $packaging->carton_weight);
                    }

                    $spec = $shipmentItem->proformaInvoiceItem?->product?->specification;
                    if ($spec && $spec->net_weight > 0 && $packaging->pcs_per_carton > 0) {
                        $set('net_weight', round((float) $spec->net_weight * $packaging->pcs_per_carton, 3));
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
                })
                ->columnSpanFull(),

            TextInput::make('description')
                ->maxLength(255)
                ->placeholder('Additional description if needed')
                ->columnSpanFull(),

            TextInput::make('carton_from')
                ->label('Carton From')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set)),

            TextInput::make('carton_to')
                ->label('Carton To')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set)),

            TextInput::make('qty_per_carton')
                ->label('Qty per Carton')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set))
                ->helperText('Auto-filled from product packaging'),

            TextInput::make('quantity')
                ->label('Number of Cartons')
                ->numeric()
                ->integer()
                ->helperText('Auto-calculated from carton range')
                ->disabled()
                ->dehydrated(),

            TextInput::make('total_quantity')
                ->label('Total Pieces')
                ->numeric()
                ->integer()
                ->helperText('Auto-calculated: cartons × qty per carton')
                ->disabled()
                ->dehydrated(),

            TextInput::make('gross_weight')
                ->label('Gross Weight / Carton (kg)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set)),

            TextInput::make('net_weight')
                ->label('Net Weight / Carton (kg)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set)),

            TextInput::make('total_gross_weight')
                ->label('Total Gross Weight (kg)')
                ->numeric()
                ->disabled()
                ->dehydrated(),

            TextInput::make('total_net_weight')
                ->label('Total Net Weight (kg)')
                ->numeric()
                ->disabled()
                ->dehydrated(),

            TextInput::make('length')
                ->label('Length (cm)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

            TextInput::make('width')
                ->label('Width (cm)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

            TextInput::make('height')
                ->label('Height (cm)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::calculateVolume($get, $set)),

            TextInput::make('volume')
                ->label('Volume / Carton (CBM)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateTotals($get, $set))
                ->helperText('Auto-calculated from dimensions'),

            TextInput::make('total_volume')
                ->label('Total Volume (CBM)')
                ->numeric()
                ->disabled()
                ->dehydrated(),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carton_range')
                    ->label('Cartons')
                    ->weight('bold')
                    ->sortable('carton_from'),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->limit(30),
                TextColumn::make('quantity')
                    ->label('# Cartons')
                    ->alignCenter()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('qty_per_carton')
                    ->label('Pcs/Carton')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('total_quantity')
                    ->label('Total Pcs')
                    ->alignCenter()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('gross_weight')
                    ->label('GW/Ctn (kg)')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('total_gross_weight')
                    ->label('Total GW (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg')),
                TextColumn::make('net_weight')
                    ->label('NW/Ctn (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_net_weight')
                    ->label('Total NW (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('volume')
                    ->label('CBM/Ctn')
                    ->alignEnd()
                    ->placeholder('—')
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

    protected static function recalculateTotals(Get $get, Set $set): void
    {
        $cartonFrom = (int) $get('carton_from');
        $cartonTo = (int) $get('carton_to');
        $qtyPerCarton = (int) $get('qty_per_carton');
        $grossWeight = (float) $get('gross_weight');
        $netWeight = (float) $get('net_weight');
        $volume = (float) $get('volume');

        $numCartons = ($cartonTo >= $cartonFrom && $cartonFrom > 0) ? ($cartonTo - $cartonFrom + 1) : 0;

        $set('quantity', $numCartons ?: null);
        $set('total_quantity', ($numCartons && $qtyPerCarton) ? $numCartons * $qtyPerCarton : null);
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

        static::recalculateTotals($get, $set);
    }
}
