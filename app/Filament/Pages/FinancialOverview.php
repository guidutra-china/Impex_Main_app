<?php

namespace App\Filament\Pages;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Widgets\FinancialStatsOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class FinancialOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Financial Overview';

    protected static ?string $title = 'Financial Overview';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'financial-overview';

    protected string $view = 'filament.pages.financial-overview';

    public string $activeTab = 'receivables';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FinancialStatsOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 6;
    }

    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            'payables' => $this->payablesTable($table),
            'schedule' => $this->scheduleTable($table),
            default => $this->receivablesTable($table),
        };
    }

    protected function receivablesTable(Table $table): Table
    {
        return $table
            ->query(
                Payment::query()
                    ->where('direction', PaymentDirection::INBOUND)
                    ->with(['company', 'paymentMethod', 'allocations.scheduleItem', 'approvedByUser', 'creator'])
            )
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Client')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allocations_summary')
                    ->label('Allocated To')
                    ->state(function ($record) {
                        $allocations = $record->allocations;
                        if ($allocations->isEmpty()) {
                            return 'No allocations';
                        }

                        return $allocations->map(function ($alloc) {
                            $label = $alloc->scheduleItem?->label ?? '?';

                            return $label . ': ' . Money::formatDisplay($alloc->allocated_amount);
                        })->join(', ');
                    })
                    ->wrap()
                    ->limit(80),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('approvedByUser.name')
                    ->label('Approved By')
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::class),
            ])
            ->recordActions([
                $this->approvePaymentAction(),
                $this->rejectPaymentAction(),
            ]);
    }

    protected function payablesTable(Table $table): Table
    {
        return $table
            ->query(
                Payment::query()
                    ->where('direction', PaymentDirection::OUTBOUND)
                    ->with(['company', 'paymentMethod', 'allocations.scheduleItem', 'approvedByUser', 'creator'])
            )
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allocations_summary')
                    ->label('Allocated To')
                    ->state(function ($record) {
                        $allocations = $record->allocations;
                        if ($allocations->isEmpty()) {
                            return 'No allocations';
                        }

                        return $allocations->map(function ($alloc) {
                            $label = $alloc->scheduleItem?->label ?? '?';

                            return $label . ': ' . Money::formatDisplay($alloc->allocated_amount);
                        })->join(', ');
                    })
                    ->wrap()
                    ->limit(80),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('approvedByUser.name')
                    ->label('Approved By')
                    ->placeholder('—'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::class),
            ])
            ->recordActions([
                $this->approvePaymentAction(),
                $this->rejectPaymentAction(),
            ]);
    }

    protected function scheduleTable(Table $table): Table
    {
        return $table
            ->query(
                PaymentScheduleItem::query()
                    ->with(['payable', 'paymentTermStage', 'waivedByUser'])
            )
            ->columns([
                TextColumn::make('company_name')
                    ->label('Client / Supplier')
                    ->state(function ($record) {
                        $payable = $record->payable;
                        if ($payable instanceof ProformaInvoice) {
                            return $payable->company?->name ?? '—';
                        }
                        if ($payable instanceof PurchaseOrder) {
                            return $payable->supplierCompany?->name ?? '—';
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
                TextColumn::make('payable')
                    ->label('Document')
                    ->formatStateUsing(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) {
                            return '—';
                        }
                        $ref = $payable->reference ?? '—';
                        $type = class_basename($payable);

                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('label')
                    ->label('Label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('percentage')
                    ->label('%')
                    ->suffix('%')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->sortable(),
                TextColumn::make('due_condition')
                    ->label('Condition')
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('is_blocking')
                    ->label('Blocking')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentScheduleStatus::class),
            ]);
    }

    protected function approvePaymentAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                app(ApprovePaymentAction::class)->approve($record);
                Notification::make()->title('Payment approved')->success()->send();
            });
    }

    protected function rejectPaymentAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')
                    ->label('Rejection Reason')
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL && auth()->user()?->can('reject-payments'))
            ->action(function ($record, array $data) {
                app(ApprovePaymentAction::class)->reject($record, $data['reason']);
                Notification::make()->title('Payment rejected')->danger()->send();
            });
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }
}
