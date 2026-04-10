<?php

namespace App\Filament\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Filament\Pages\Widgets\CashFlowProjection;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use App\Filament\Pages\Widgets\FinancialStatsOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FinancialOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.financial_overview');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.financial_overview');
    }

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'financial-overview';

    protected string $view = 'filament.pages.financial-overview';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FinancialStatsOverview::class,
            CashFlowProjection::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $this->scheduleTable($table);
    }

    protected function scheduleTable(Table $table): Table
    {
        return $table
            ->query(
                PaymentScheduleItem::query()
                    ->with(['payable', 'source', 'paymentTermStage', 'waivedByUser', 'shipment'])
            )
            ->columns([
                TextColumn::make('company_name')
                    ->label(__('forms.labels.client_supplier'))
                    ->state(function ($record) {
                        $payable = $record->payable;
                        if ($payable instanceof ProformaInvoice) {
                            return $payable->company?->name ?? '—';
                        }
                        if ($payable instanceof PurchaseOrder) {
                            return $payable->supplierCompany?->name ?? '—';
                        }
                        if ($payable instanceof ShipmentPlan) {
                            return $payable->supplierCompany?->name ?? '—';
                        }
                        if ($payable instanceof Shipment) {
                            return $payable->company?->name ?? '—';
                        }

                        return '—';
                    })
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHasMorph('payable', [ProformaInvoice::class], function ($pq) use ($search) {
                                $pq->whereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                            })->orWhereHasMorph('payable', [PurchaseOrder::class], function ($pq) use ($search) {
                                $pq->whereHas('supplierCompany', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                            });
                        });
                    })
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderByRaw("
                            COALESCE(
                                (SELECT c.name FROM proforma_invoices pi
                                 JOIN companies c ON c.id = pi.company_id
                                 WHERE pi.id = payment_schedule_items.payable_id
                                 AND payment_schedule_items.payable_type = ?
                                 LIMIT 1),
                                (SELECT c.name FROM purchase_orders po
                                 JOIN companies c ON c.id = po.supplier_company_id
                                 WHERE po.id = payment_schedule_items.payable_id
                                 AND payment_schedule_items.payable_type = ?
                                 LIMIT 1)
                            ) {$direction}
                        ", [(new ProformaInvoice)->getMorphClass(), (new PurchaseOrder)->getMorphClass()]);
                    }),
                TextColumn::make('payable_type_badge')
                    ->label(__('forms.labels.type'))
                    ->state(function ($record) {
                        return match (true) {
                            $record->payable instanceof ProformaInvoice => 'PI',
                            $record->payable instanceof PurchaseOrder => 'PO',
                            $record->payable instanceof ShipmentPlan => 'SP',
                            $record->payable instanceof Shipment => 'Shipment',
                            default => '—',
                        };
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'PI' => 'primary',
                        'PO' => 'warning',
                        'SP' => 'info',
                        'Shipment' => 'info',
                        default => 'gray',
                    })
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('payable_type', $direction)),
                TextColumn::make('payable_ref')
                    ->label(__('forms.labels.reference'))
                    ->state(fn ($record) => $record->payable?->reference ?? '—')
                    ->url(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) {
                            return null;
                        }

                        return match (true) {
                            $payable instanceof ProformaInvoice => ProformaInvoiceResource::getUrl('edit', ['record' => $payable]),
                            $payable instanceof PurchaseOrder => PurchaseOrderResource::getUrl('edit', ['record' => $payable]),
                            $payable instanceof ShipmentPlan => ShipmentPlanResource::getUrl('edit', ['record' => $payable]),
                            $payable instanceof Shipment => ShipmentResource::getUrl('edit', ['record' => $payable]),
                            default => null,
                        };
                    })
                    ->color('primary')
                    ->weight('bold')
                    ->openUrlInNewTab()
                    ->searchable(query: fn ($query, string $search) => $query->whereHasMorph(
                        'payable',
                        [ProformaInvoice::class, PurchaseOrder::class, Shipment::class, ShipmentPlan::class],
                        fn ($q) => $q->where('reference', 'like', "%{$search}%")
                    )),
                TextColumn::make('source')
                    ->label(__('forms.labels.source'))
                    ->formatStateUsing(function ($record) {
                        $source = $record->source;
                        if (! $source) {
                            return '—';
                        }
                        $type = class_basename($source);
                        $ref = $source->reference ?? $source->name ?? $source->id;

                        return "{$type}: {$ref}";
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('label')
                    ->label(__('forms.labels.schedule_stage'))
                    ->formatStateUsing(function ($record) {
                        $label = preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $record->label ?? '');
                        $shipmentRef = $record->shipment
                            ? ($record->shipment->bl_number ?: $record->shipment->reference)
                            : null;

                        return $shipmentRef ? "{$label} [{$shipmentRef}]" : $label;
                    })
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->sortable(),
                TextColumn::make('due_condition')
                    ->label(__('forms.labels.condition'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('is_blocking')
                    ->label(__('forms.labels.blocking'))
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentScheduleStatus::class),
            ]);
    }
}
