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
use App\Filament\Pages\Widgets\CashFlowProjection;
use App\Filament\Pages\Widgets\FinancialStatsOverview;
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

    public string $activeTab = 'receivables';

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
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allocations_summary')
                    ->label(__('forms.labels.allocated_to'))
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
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label(__('forms.labels.total'))
                        ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('paymentMethod.name')
                    ->label(__('forms.labels.method'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('approvedByUser.name')
                    ->label(__('forms.labels.approved_by'))
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
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
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.supplier'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('allocations_summary')
                    ->label(__('forms.labels.allocated_to'))
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
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label(__('forms.labels.total'))
                        ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('paymentMethod.name')
                    ->label(__('forms.labels.method'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('approvedByUser.name')
                    ->label(__('forms.labels.approved_by'))
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
                    ->label(__('forms.labels.client_supplier'))
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
                    ->label(__('forms.labels.document'))
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
                    ->label(__('forms.labels.label'))
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

    protected function approvePaymentAction(): Action
    {
        return Action::make('approve')
            ->label(__('forms.labels.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                app(ApprovePaymentAction::class)->approve($record);
                Notification::make()->title(__('messages.payment_approved'))->success()->send();
            });
    }

    protected function rejectPaymentAction(): Action
    {
        return Action::make('reject')
            ->label(__('forms.labels.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.rejection_reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL && auth()->user()?->can('reject-payments'))
            ->action(function ($record, array $data) {
                app(ApprovePaymentAction::class)->reject($record, $data['reason']);
                Notification::make()->title(__('messages.payment_rejected'))->danger()->send();
            });
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }
}
