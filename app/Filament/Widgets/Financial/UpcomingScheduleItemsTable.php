<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Support\PayableResourceUrl;
use App\Filament\Widgets\Financial\Concerns\HasFinancialDashboardGate;
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
    use HasFinancialDashboardGate;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    abstract protected function direction(): PaymentDirection;

    abstract protected function openItemsQuery(): Builder;

    abstract protected function tableHeading(): string;

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->tableHeading())
            ->query(
                $this->openItemsQuery()
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '>=', now()->startOfDay())
                    ->orderBy('due_date')
                    ->orderBy('id')
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
                    ->url(fn (PaymentScheduleItem $record) => PayableResourceUrl::for($record->payable))
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
