<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proformaInvoice.reference')
                    ->label('Proforma Invoice')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->proforma_invoice_id
                        ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                        : null
                    )
                    ->color('primary'),
                TextColumn::make('supplierCompany.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('incoterm')
                    ->label('Incoterm')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('total')
                    ->label('Total')
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('issue_date')
                    ->label('Issue Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('expected_delivery_date')
                    ->label('Expected Delivery')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmation_method')
                    ->label('Confirmed Via')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class),
                SelectFilter::make('supplier_company_id')
                    ->label('Supplier')
                    ->relationship('supplierCompany', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No purchase orders')
            ->emptyStateDescription('Generate purchase orders from a confirmed proforma invoice.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}
