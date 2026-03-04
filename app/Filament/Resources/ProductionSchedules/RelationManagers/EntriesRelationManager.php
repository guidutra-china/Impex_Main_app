<?php

namespace App\Filament\Resources\ProductionSchedules\RelationManagers;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Production Entries';

    public function form(Schema $schema): Schema
    {
        $piId = $this->getOwnerRecord()->proforma_invoice_id;

        return $schema->components([
            Select::make('proforma_invoice_item_id')
                ->label(__('forms.labels.product'))
                ->options(
                    fn () => ProformaInvoiceItem::where('proforma_invoice_id', $piId)
                        ->with('product')
                        ->get()
                        ->mapWithKeys(fn ($item) => [
                            $item->id => ($item->product?->name ?? $item->description) . " (Qty: {$item->quantity})",
                        ])
                )
                ->searchable()
                ->required(),
            DatePicker::make('production_date')
                ->label(__('forms.labels.production_date'))
                ->required(),
            TextInput::make('quantity')
                ->label(__('forms.labels.quantity'))
                ->numeric()
                ->required()
                ->minValue(1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proformaInvoiceItem.product.name')
                    ->label(__('forms.labels.product'))
                    ->default(fn ($record) => $record->proformaInvoiceItem?->description ?? '—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label(__('forms.labels.production_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.quantity'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('production_date', 'asc')
            ->groups([
                'proformaInvoiceItem.product.name',
                'production_date',
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No production entries')
            ->emptyStateDescription('Add entries manually or import from a supplier spreadsheet.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
