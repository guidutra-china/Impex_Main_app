<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\ShipmentItem;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
            TextInput::make('carton_number')
                ->label('Carton / Package #')
                ->required()
                ->maxLength(50)
                ->placeholder('CTN-001'),

            Select::make('shipment_item_id')
                ->label('Product')
                ->options(function () {
                    return $this->getOwnerRecord()
                        ->items()
                        ->with('proformaInvoiceItem.product')
                        ->get()
                        ->mapWithKeys(fn ($item) => [
                            $item->id => $item->product_name,
                        ]);
                })
                ->searchable()
                ->preload(),

            TextInput::make('description')
                ->maxLength(255)
                ->placeholder('Additional description if needed')
                ->columnSpanFull(),

            TextInput::make('quantity')
                ->numeric()
                ->integer()
                ->minValue(1),

            TextInput::make('gross_weight')
                ->label('Gross Weight (kg)')
                ->numeric(),

            TextInput::make('net_weight')
                ->label('Net Weight (kg)')
                ->numeric(),

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
                ->label('Volume (CBM)')
                ->numeric()
                ->helperText('Auto-calculated from dimensions, or enter manually'),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carton_number')
                    ->label('Carton #')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->limit(30),
                TextColumn::make('description')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter()
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('gross_weight')
                    ->label('Gross (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg')),
                TextColumn::make('net_weight')
                    ->label('Net (kg)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total')->suffix(' kg')),
                TextColumn::make('length')
                    ->label('L (cm)')
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('width')
                    ->label('W (cm)')
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('height')
                    ->label('H (cm)')
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('volume')
                    ->label('Vol. (CBM)')
                    ->alignEnd()
                    ->placeholder('—')
                    ->summarize(Sum::make()->label('Total')->suffix(' CBM')),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add Carton')
                    ->icon('heroicon-o-plus'),
            ])
            ->emptyStateHeading('No packing details')
            ->emptyStateDescription('Add carton/package details for the packing list.')
            ->emptyStateIcon('heroicon-o-archive-box')
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
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
    }
}
