<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Settings\Models\PaymentMethod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentScheduleItems';

    protected static ?string $title = 'Payments';

    protected static BackedEnum|string|null $icon = 'heroicon-o-banknotes';

    protected PaymentDirection $defaultDirection = PaymentDirection::INBOUND;

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
                TextColumn::make('allocations_summary')
                    ->label('Allocated To')
                    ->state(function ($record) {
                        $allocations = $record->allocations()->with('scheduleItem')->get();
                        if ($allocations->isEmpty()) {
                            return 'No allocations';
                        }

                        return $allocations->map(function ($alloc) {
                            $label = $alloc->scheduleItem?->label ?? '?';
                            $amount = Money::format($alloc->allocated_amount);

                            return "{$label}: {$amount}";
                        })->join(', ');
                    })
                    ->wrap()
                    ->limit(60),
                TextColumn::make('amount')
                    ->label('Total Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
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
                TextColumn::make('approvedByUser.name')
                    ->label('Approved By')
                    ->placeholder('—'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->headerActions([
                $this->recordPaymentAction(),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
            ]);
    }

    protected function getPaymentsQuery(): Builder
    {
        $record = $this->getOwnerRecord();
        $scheduleItemIds = $record->paymentScheduleItems()->pluck('id');

        return Payment::query()
            ->whereHas('allocations', fn ($q) => $q->whereIn('payment_schedule_item_id', $scheduleItemIds))
            ->with(['company', 'allocations.scheduleItem', 'paymentMethod', 'approvedByUser', 'creator']);
    }

    protected function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record Payment')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('create-payments'))
            ->form([
                Section::make('Payment Details')->columns(2)->schema([
                    Select::make('direction')
                        ->label('Direction')
                        ->options(PaymentDirection::class)
                        ->default($this->getDefaultDirection()->value)
                        ->required(),
                    DatePicker::make('payment_date')
                        ->label('Payment Date')
                        ->default(now())
                        ->required(),
                    TextInput::make('amount')
                        ->label('Total Payment Amount')
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0.0001)
                        ->required()
                        ->live(onBlur: true),
                    Select::make('currency_code')
                        ->label('Payment Currency')
                        ->options(fn () => Currency::pluck('code', 'code'))
                        ->default(fn () => $this->getOwnerRecord()->currency_code)
                        ->required()
                        ->live(),
                    Select::make('payment_method_id')
                        ->label('Payment Method')
                        ->options(fn () => PaymentMethod::active()->pluck('name', 'id')),
                    Select::make('bank_account_id')
                        ->label('Bank Account')
                        ->options(fn () => BankAccount::active()->get()->mapWithKeys(fn ($ba) => [
                            $ba->id => $ba->bank_name . ' — ' . $ba->account_name . ' (' . $ba->currency?->code . ')',
                        ])),
                    TextInput::make('reference')
                        ->label('Reference (SWIFT, Transfer #)')
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(2)
                        ->columnSpanFull(),
                    FileUpload::make('attachment_path')
                        ->label('Attachment (Receipt/SWIFT)')
                        ->directory('payment-attachments')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(5120)
                        ->columnSpanFull(),
                ]),
                Section::make('Allocations')->schema([
                    Repeater::make('allocations')
                        ->label('Distribute payment across schedule items')
                        ->schema([
                            Select::make('payment_schedule_item_id')
                                ->label('Schedule Item')
                                ->options(function () {
                                    $record = $this->getOwnerRecord();

                                    return PaymentScheduleItem::where('payable_type', get_class($record))
                                        ->where('payable_id', $record->getKey())
                                        ->whereNotIn('status', [
                                            PaymentScheduleStatus::PAID->value,
                                            PaymentScheduleStatus::WAIVED->value,
                                        ])
                                        ->get()
                                        ->mapWithKeys(fn ($item) => [
                                            $item->id => $item->label
                                                . ' — ' . $item->currency_code
                                                . ' ' . Money::format($item->remaining_amount) . ' remaining',
                                        ]);
                                })
                                ->required()
                                ->distinct()
                                ->columnSpan(2),
                            TextInput::make('allocated_amount')
                                ->label('Allocated Amount')
                                ->numeric()
                                ->step('0.0001')
                                ->minValue(0.0001)
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('exchange_rate')
                                ->label('Exchange Rate')
                                ->numeric()
                                ->step('0.00000001')
                                ->helperText('Leave empty if same currency')
                                ->columnSpan(1),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add allocation')
                        ->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data) {
                $record = $this->getOwnerRecord();
                $totalAmountMinor = Money::toMinor((float) $data['amount']);
                $paymentCurrencyCode = $data['currency_code'];
                $documentCurrencyCode = $record->currency_code;

                $companyId = $this->resolveCompanyId($record);

                $payment = Payment::create([
                    'direction' => $data['direction'],
                    'company_id' => $companyId,
                    'amount' => $totalAmountMinor,
                    'currency_code' => $paymentCurrencyCode,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'payment_date' => $data['payment_date'],
                    'reference' => $data['reference'] ?? null,
                    'status' => PaymentStatus::PENDING_APPROVAL,
                    'notes' => $data['notes'] ?? null,
                    'attachment_path' => $data['attachment_path'] ?? null,
                ]);

                foreach ($data['allocations'] as $allocationData) {
                    $allocatedMinor = Money::toMinor((float) $allocationData['allocated_amount']);
                    $exchangeRate = ! empty($allocationData['exchange_rate']) ? (float) $allocationData['exchange_rate'] : null;

                    $allocatedInDocCurrency = $allocatedMinor;

                    if ($paymentCurrencyCode !== $documentCurrencyCode) {
                        if ($exchangeRate) {
                            $allocatedInDocCurrency = (int) round($allocatedMinor * $exchangeRate);
                        } else {
                            $paymentCurrency = Currency::where('code', $paymentCurrencyCode)->first();
                            $documentCurrency = Currency::where('code', $documentCurrencyCode)->first();

                            if ($paymentCurrency && $documentCurrency) {
                                $converted = ExchangeRate::convert(
                                    $paymentCurrency->id,
                                    $documentCurrency->id,
                                    Money::toMajor($allocatedMinor)
                                );

                                if ($converted !== null) {
                                    $allocatedInDocCurrency = Money::toMinor($converted);
                                    $exchangeRate = $allocatedInDocCurrency / max($allocatedMinor, 1);
                                }
                            }
                        }
                    }

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'payment_schedule_item_id' => $allocationData['payment_schedule_item_id'],
                        'allocated_amount' => $allocatedMinor,
                        'exchange_rate' => $exchangeRate,
                        'allocated_amount_in_document_currency' => $allocatedInDocCurrency,
                    ]);
                }

                Notification::make()
                    ->title('Payment recorded with ' . count($data['allocations']) . ' allocation(s) — pending approval')
                    ->success()
                    ->send();
            });
    }

    protected function resolveCompanyId($record): ?int
    {
        if (method_exists($record, 'company') && $record->company_id) {
            return $record->company_id;
        }

        if (method_exists($record, 'supplierCompany') && $record->supplier_company_id) {
            return $record->supplier_company_id;
        }

        return null;
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Payment')
            ->modalDescription(fn ($record) => 'Approve payment of ' . Money::format($record->amount) . ' ' . $record->currency_code . '?')
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                app(ApprovePaymentAction::class)->approve($record);

                Notification::make()->title('Payment approved')->success()->send();
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject Payment')
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

    protected function getDefaultDirection(): PaymentDirection
    {
        return $this->defaultDirection;
    }
}
