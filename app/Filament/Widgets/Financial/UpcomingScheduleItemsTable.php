<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compact "next due" worklist for the financial dashboard: open items with a
 * due date from today onward, soonest first. Subclasses pick the direction.
 */
abstract class UpcomingScheduleItemsTable extends TableWidget
{
    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    abstract protected function direction(): PaymentDirection;

    abstract protected function openItemsQuery(): Builder;

    abstract protected function tableHeading(): string;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->tableHeading())
            ->query(
                $this->openItemsQuery()
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '>=', now()->startOfDay())
                    ->orderBy('due_date')
            )
            ->paginated([5])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('counterparty')
                    ->label(__('forms.labels.client_supplier'))
                    ->state(fn (PaymentScheduleItem $record) => $record->counterpartyName($this->direction()) ?? '—'),
                TextColumn::make('payable_ref')
                    ->label(__('forms.labels.document'))
                    ->state(fn (PaymentScheduleItem $record) => $record->payable?->reference ?? '—')
                    ->url(function (PaymentScheduleItem $record) {
                        $payable = $record->payable;

                        return match (true) {
                            $payable instanceof ProformaInvoice => ProformaInvoiceResource::getUrl('view', ['record' => $payable]),
                            $payable instanceof PurchaseOrder => PurchaseOrderResource::getUrl('view', ['record' => $payable]),
                            $payable instanceof Shipment => ShipmentResource::getUrl('view', ['record' => $payable]),
                            $payable instanceof DebitNote => DebitNoteResource::getUrl('view', ['record' => $payable]),
                            default => null,
                        };
                    })
                    ->color('primary'),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y'),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining_amount'))
                    ->state(fn (PaymentScheduleItem $record) => $record->currency_code.' '.Money::formatDisplay((int) $record->remaining_amount))
                    ->alignEnd(),
            ]);
    }
}
