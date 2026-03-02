<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Filament\Actions\StatusTransitionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('proformaInvoice.reference')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->proforma_invoice_id
                        ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                        : null
                    )
                    ->color('primary'),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('supplier_invoice_number')
                    ->label(__('forms.labels.supplier_invoice'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->sortable(query: fn ($query, $direction) => $query->orderByRaw(
                        '(SELECT COALESCE(SUM(quantity * unit_price), 0) FROM purchase_order_items WHERE purchase_order_id = purchase_orders.id) ' . $direction
                    ))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_date')
                    ->label(__('forms.labels.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('expected_delivery_date')
                    ->label(__('forms.labels.expected_delivery'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmation_method')
                    ->label(__('forms.labels.confirmed_via'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsible.name')
                    ->label(__('forms.labels.responsible'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class),
                SelectFilter::make('supplier_company_id')
                    ->label(__('forms.labels.supplier'))
                    ->relationship('supplierCompany', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                TrashedFilter::make(),
            ])
            ->recordActions([
                StatusTransitionActions::make(PurchaseOrderStatus::class),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No purchase orders')
            ->emptyStateDescription('Generate purchase orders from a confirmed proforma invoice.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}
