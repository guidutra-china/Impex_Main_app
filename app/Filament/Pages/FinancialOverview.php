<?php

namespace App\Filament\Pages;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
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

    protected static string $view = 'filament.pages.financial-overview';

    public string $activeTab = 'receivables';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            'payables' => $this->payablesTable($table),
            'schedule' => $this->scheduleTable($table),
            'additional_costs' => $this->additionalCostsTable($table),
            default => $this->receivablesTable($table),
        };
    }

    protected function receivablesTable(Table $table): Table
    {
        return $table
            ->query(
                Payment::query()
                    ->where('direction', PaymentDirection::INBOUND)
                    ->with(['payable', 'paymentMethod', 'scheduleItem', 'approvedByUser', 'creator'])
            )
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('payable')
                    ->label('Document')
                    ->formatStateUsing(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) return '—';
                        $ref = $payable->reference ?? '—';
                        $type = class_basename($payable);
                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('scheduleItem.label')
                    ->label('Schedule Item')
                    ->placeholder('Ad-hoc'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('amount_in_document_currency')
                    ->label('Doc. Amount')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
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
                    ->with(['payable', 'paymentMethod', 'scheduleItem', 'approvedByUser', 'creator'])
            )
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('payable')
                    ->label('Document')
                    ->formatStateUsing(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) return '—';
                        $ref = $payable->reference ?? '—';
                        $type = class_basename($payable);
                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('scheduleItem.label')
                    ->label('Schedule Item')
                    ->placeholder('Ad-hoc'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('amount_in_document_currency')
                    ->label('Doc. Amount')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
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
                TextColumn::make('payable')
                    ->label('Document')
                    ->formatStateUsing(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) return '—';
                        $ref = $payable->reference ?? '—';
                        $type = class_basename($payable);
                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('label')
                    ->label('Label')
                    ->searchable(),
                TextColumn::make('percentage')
                    ->label('%')
                    ->suffix('%')
                    ->alignCenter(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('due_condition')
                    ->label('Condition')
                    ->badge(),
                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('is_blocking')
                    ->label('Blocking')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentScheduleStatus::class),
            ]);
    }

    protected function additionalCostsTable(Table $table): Table
    {
        return $table
            ->query(
                AdditionalCost::query()
                    ->with(['costable', 'supplierCompany', 'creator'])
            )
            ->columns([
                TextColumn::make('costable')
                    ->label('Document')
                    ->formatStateUsing(function ($record) {
                        $costable = $record->costable;
                        if (! $costable) return '—';
                        $ref = $costable->reference ?? '—';
                        $type = class_basename($costable);
                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('amount_in_document_currency')
                    ->label('Doc. Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('billable_to')
                    ->label('Billable To')
                    ->badge(),
                TextColumn::make('supplierCompany.name')
                    ->label('Supplier')
                    ->placeholder('—'),
                TextColumn::make('cost_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->defaultSort('cost_date', 'desc')
            ->filters([
                SelectFilter::make('cost_type')
                    ->options(AdditionalCostType::class),
                SelectFilter::make('billable_to')
                    ->options(BillableTo::class),
                SelectFilter::make('status')
                    ->options(AdditionalCostStatus::class),
            ]);
    }

    protected function approvePaymentAction(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('approve')
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

    protected function rejectPaymentAction(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('reject')
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

    public function getStats(): array
    {
        $pendingReceivables = Payment::inbound()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->sum('amount');

        $approvedReceivables = Payment::inbound()
            ->approved()
            ->sum('amount');

        $pendingPayables = Payment::outbound()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->sum('amount');

        $approvedPayables = Payment::outbound()
            ->approved()
            ->sum('amount');

        $pendingAdditionalCosts = AdditionalCost::where('status', AdditionalCostStatus::PENDING)
            ->sum('amount_in_document_currency');

        $blockingScheduleItems = PaymentScheduleItem::where('is_blocking', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->count();

        return [
            'pending_receivables' => Money::format($pendingReceivables),
            'approved_receivables' => Money::format($approvedReceivables),
            'pending_payables' => Money::format($pendingPayables),
            'approved_payables' => Money::format($approvedPayables),
            'pending_additional_costs' => Money::format($pendingAdditionalCosts),
            'blocking_schedule_items' => $blockingScheduleItems,
        ];
    }
}
