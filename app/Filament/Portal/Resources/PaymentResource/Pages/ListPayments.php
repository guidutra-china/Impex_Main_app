<?php

namespace App\Filament\Portal\Resources\PaymentResource\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\PaymentResource;
use App\Filament\Portal\Widgets\PaymentsListStats;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected string $view = 'portal.pages.list-payments';

    public string $currentView = 'payments';

    public function getTitle(): string|Htmlable
    {
        return __('navigation.resources.payments');
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'schedule') {
            return $this->scheduleTable($table);
        }

        return parent::table($table);
    }

    protected function scheduleTable(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                PaymentScheduleItem::query()
                    ->with(['payable', 'paymentTermStage'])
                    ->where(function ($query) use ($tenant) {
                        $query->whereHasMorph('payable', [ProformaInvoice::class], function ($q) use ($tenant) {
                            $q->where('company_id', $tenant->id);
                        })->orWhereHasMorph('payable', [Shipment::class], function ($q) use ($tenant) {
                            $q->where('company_id', $tenant->id);
                        });
                    })
            )
            ->columns([
                TextColumn::make('payable_type_label')
                    ->label(__('forms.labels.type'))
                    ->state(function ($record) {
                        $payable = $record->payable;

                        return match (true) {
                            $payable instanceof ProformaInvoice => 'PI',
                            $payable instanceof Shipment => 'Shipment',
                            $payable instanceof \App\Domain\PurchaseOrders\Models\PurchaseOrder => 'PO',
                            default => '—',
                        };
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'PI' => 'primary',
                        'Shipment' => 'info',
                        'PO' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('payable_type', $direction)),
                TextColumn::make('payable_ref')
                    ->label(__('forms.labels.reference'))
                    ->state(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) {
                            return '—';
                        }
                        if ($payable instanceof Shipment && $payable->bl_number) {
                            return $payable->bl_number;
                        }

                        return $payable->reference ?? '—';
                    })
                    ->searchable(query: fn ($query, string $search) => $query->whereHasMorph(
                        'payable',
                        [ProformaInvoice::class, Shipment::class],
                        fn ($q) => $q->where('reference', 'like', "%{$search}%")
                            ->orWhere('bl_number', 'like', "%{$search}%")
                    ))
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->leftJoin('proforma_invoices', function ($join) {
                            $join->on('payment_schedule_items.payable_id', '=', 'proforma_invoices.id')
                                ->where('payment_schedule_items.payable_type', (new ProformaInvoice)->getMorphClass());
                        })
                        ->leftJoin('shipments', function ($join) {
                            $join->on('payment_schedule_items.payable_id', '=', 'shipments.id')
                                ->where('payment_schedule_items.payable_type', (new Shipment)->getMorphClass());
                        })
                        ->orderByRaw("COALESCE(shipments.bl_number, shipments.reference, proforma_invoices.reference) {$direction}")
                        ->select('payment_schedule_items.*')
                    )
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('label')
                    ->label(__('forms.labels.label'))
                    ->formatStateUsing(function ($state) {
                        // Remove "30% — " prefix since percentage and condition have their own columns
                        return preg_replace('/^\d+%\s*[—–-]\s*/', '', $state ?? '');
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignEnd()
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
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentScheduleStatus::class),
            ])
            ->recordUrl(null)
            ->recordActions([]);
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }
}
