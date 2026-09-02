<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Actions\SyncSupplierProductPricesAction;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Actions\QuickViewAction;
use App\Filament\Actions\StatusTransitionActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('proformaInvoice.company'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable(query: function ($query, string $search): void {
                        $query->where('reference', 'like', "%{$search}%")
                            ->orWhereHas('proformaInvoice.company', fn ($q) => $q
                                ->where('name', 'like', "%{$search}%")
                            )
                            ->orWhereHas('items', function ($q) use ($search) {
                                $q->where('description', 'like', "%{$search}%")
                                    ->orWhereHas('product', fn ($pq) => $pq
                                        ->where('name', 'like', "%{$search}%")
                                        ->orWhere('model_number', 'like', "%{$search}%")
                                        ->orWhere('sku', 'like', "%{$search}%")
                                    );
                            });
                    })
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('supplier_invoice_number')
                    ->label(__('forms.labels.supplier_invoice'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('proformaInvoice.reference')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->proforma_invoice_id
                        ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                        : null
                    )
                    ->color('primary')
                    // Mostra o cliente da PI logo abaixo, em letras menores, para
                    // identificar rapidamente para qual cliente é a PO.
                    ->description(fn ($record) => $record->proformaInvoice?->company?->name),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
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
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state))
                    ->sortable(query: fn ($query, $direction) => $query->orderByRaw(
                        '(SELECT COALESCE(SUM(quantity * unit_price), 0) FROM purchase_order_items WHERE purchase_order_id = purchase_orders.id) '.$direction
                    ))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('schedule_paid_total')
                    ->label(__('forms.labels.paid'))
                    ->getStateUsing(fn ($record) => $record->schedule_paid_total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state))
                    ->sortable(query: fn ($query, $direction) => $query->orderByRaw(
                        '(SELECT COALESCE(SUM(pa.allocated_amount_in_document_currency), 0) '
                        .'FROM payment_allocations pa '
                        .'INNER JOIN payment_schedule_items psi ON psi.id = pa.payment_schedule_item_id '
                        .'INNER JOIN payments p ON p.id = pa.payment_id '
                        ."WHERE psi.payable_type = 'App\\\\Domain\\\\PurchaseOrders\\\\Models\\\\PurchaseOrder' "
                        .'AND psi.payable_id = purchase_orders.id '
                        ."AND p.status = 'approved') ".$direction
                    ))
                    ->color(fn ($record) => match (true) {
                        $record->total > 0 && $record->schedule_paid_total >= $record->total => 'success',
                        $record->schedule_paid_total > 0 => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd(),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_date')
                    ->label(__('forms.labels.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                // Editável na própria lista: a data de entrega muda com
                // frequência e abrir a PO só para isso era o passo a mais.
                TextInputColumn::make('expected_delivery_date')
                    ->label(__('forms.labels.expected_delivery'))
                    ->type('date')
                    // O wrapper da coluna tem x-on:click.prevent.stop (para o
                    // clique não abrir a linha) e o preventDefault cancela a
                    // abertura do calendário nativo — só o ícone aparece e nada
                    // acontece ao clicar. O Filament corrige isso com showPicker()
                    // apenas para type=color (issue #15895); aqui replicamos
                    // para date.
                    ->extraInputAttributes([
                        'onclick' => "if (typeof this.showPicker === 'function') { this.showPicker() }",
                    ])
                    ->rules(['nullable', 'date'])
                    ->disabled(fn () => ! auth()->user()?->can('edit-purchase-orders'))
                    // O cast do model devolve Carbon; o input date exige Y-m-d.
                    ->getStateUsing(fn (PurchaseOrder $record) => $record->expected_delivery_date?->toDateString())
                    ->updateStateUsing(function (PurchaseOrder $record, $state) {
                        $record->update([
                            'expected_delivery_date' => filled($state) ? $state : null,
                        ]);

                        return $record->expected_delivery_date?->toDateString();
                    })
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
                SelectFilter::make('client')
                    ->label(__('forms.labels.client'))
                    ->options(fn () => \App\Domain\CRM\Models\Company::query()
                        ->whereIn('id', \App\Domain\ProformaInvoices\Models\ProformaInvoice::query()
                            ->has('purchaseOrders')
                            ->distinct()
                            ->pluck('company_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): void {
                        if (filled($data['value'] ?? null)) {
                            $piIds = \App\Domain\ProformaInvoices\Models\ProformaInvoice::query()
                                ->where('company_id', $data['value'])
                                ->pluck('id');

                            $query->whereIn('proforma_invoice_id', $piIds);
                        }
                    })
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                Filter::make('stalled')
                    ->label(__('forms.labels.stalled_15_days'))
                    ->toggle()
                    // Mirrors the "stalled POs" alert on the Operational Dashboard.
                    ->query(fn ($query) => $query
                        ->whereIn('status', [
                            PurchaseOrderStatus::CONFIRMED,
                            PurchaseOrderStatus::IN_PRODUCTION,
                            PurchaseOrderStatus::AWAITING_SHIPMENT,
                        ])
                        ->where('updated_at', '<', now()->subDays(15))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    StatusTransitionActions::make(PurchaseOrderStatus::class, [
                        'confirmed' => [
                            'icon' => 'heroicon-o-check-circle',
                            'color' => 'success',
                            'requiresConfirmation' => true,
                            'sideEffects' => fn ($record) => app(SyncSupplierProductPricesAction::class)->execute($record),
                        ],
                    ]),
                    QuickViewAction::make(PurchaseOrderResource::class),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
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
