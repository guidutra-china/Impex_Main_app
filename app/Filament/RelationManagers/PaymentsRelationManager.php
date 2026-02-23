<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Payments\PaymentResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentScheduleItems';

    protected static ?string $title = 'Payments';

    protected static BackedEnum|string|null $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getPaymentsQuery())
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->placeholder('—'),
                TextColumn::make('allocations_for_this_document')
                    ->label('Allocated Here')
                    ->state(function ($record) {
                        $ownerRecord = $this->getOwnerRecord();
                        $scheduleItemIds = $ownerRecord->paymentScheduleItems()->pluck('id');

                        $allocations = $record->allocations()
                            ->with('scheduleItem')
                            ->whereIn('payment_schedule_item_id', $scheduleItemIds)
                            ->get();

                        if ($allocations->isEmpty()) {
                            return '—';
                        }

                        return $allocations->map(function ($alloc) {
                            $label = $alloc->scheduleItem?->label ?? '?';
                            $amount = Money::format($alloc->allocated_amount);
                            return "{$label}: {$amount}";
                        })->join(', ');
                    })
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Total Payment')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('unallocated_amount')
                    ->label('Unallocated')
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->reference),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->headerActions([
                Action::make('recordPayment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->url(fn () => PaymentResource::getUrl('create'))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                TableAction::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => PaymentResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }

    protected function getPaymentsQuery(): Builder
    {
        $record = $this->getOwnerRecord();
        $scheduleItemIds = $record->paymentScheduleItems()->pluck('id');

        return Payment::query()
            ->whereHas('allocations', fn ($q) => $q->whereIn('payment_schedule_item_id', $scheduleItemIds))
            ->with(['company', 'allocations.scheduleItem', 'paymentMethod', 'approvedByUser']);
    }
}
