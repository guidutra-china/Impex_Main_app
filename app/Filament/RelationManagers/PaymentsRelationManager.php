<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
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
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    protected static BackedEnum|string|null $icon = 'heroicon-o-banknotes';

    protected PaymentDirection $defaultDirection = PaymentDirection::INBOUND;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge(),
                TextColumn::make('scheduleItem.label')
                    ->label('Schedule Item')
                    ->placeholder('Ad-hoc payment'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('exchange_rate')
                    ->label('Rate')
                    ->placeholder('—')
                    ->numeric(8),
                TextColumn::make('amount_in_document_currency')
                    ->label('Doc. Amount')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
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

    protected function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record Payment')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->form([
                Grid::make(2)->schema([
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
                                    $item->id => $item->label . ' — ' . Money::format($item->remaining_amount) . ' remaining',
                                ]);
                        })
                        ->placeholder('Ad-hoc payment (no schedule item)')
                        ->columnSpanFull(),
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
                        ->label('Amount')
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0.0001)
                        ->required(),
                    Select::make('currency_code')
                        ->label('Payment Currency')
                        ->options(fn () => Currency::pluck('code', 'code'))
                        ->default(fn () => $this->getOwnerRecord()->currency_code)
                        ->required()
                        ->live(),
                    TextInput::make('exchange_rate')
                        ->label('Exchange Rate')
                        ->numeric()
                        ->step('0.00000001')
                        ->helperText('Rate to convert payment currency to document currency. Leave empty if same currency.')
                        ->visible(fn ($get) => $get('currency_code') && $get('currency_code') !== $this->getOwnerRecord()->currency_code),
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
            ])
            ->action(function (array $data) {
                $record = $this->getOwnerRecord();

                $amountMinor = Money::toMinor((float) $data['amount']);
                $documentCurrencyCode = $record->currency_code;
                $paymentCurrencyCode = $data['currency_code'];

                $exchangeRate = null;
                $amountInDocCurrency = $amountMinor;

                if ($paymentCurrencyCode !== $documentCurrencyCode) {
                    $exchangeRate = $data['exchange_rate'] ?? null;

                    if ($exchangeRate) {
                        $amountInDocCurrency = (int) round($amountMinor * (float) $exchangeRate);
                    } else {
                        $paymentCurrency = Currency::where('code', $paymentCurrencyCode)->first();
                        $documentCurrency = Currency::where('code', $documentCurrencyCode)->first();

                        if ($paymentCurrency && $documentCurrency) {
                            $converted = ExchangeRate::convert(
                                $paymentCurrency->id,
                                $documentCurrency->id,
                                Money::toMajor($amountMinor)
                            );

                            if ($converted !== null) {
                                $amountInDocCurrency = Money::toMinor($converted);
                                $exchangeRate = $amountInDocCurrency / max($amountMinor, 1);
                            }
                        }
                    }
                }

                $record->payments()->create([
                    'payment_schedule_item_id' => $data['payment_schedule_item_id'] ?? null,
                    'direction' => $data['direction'],
                    'amount' => $amountMinor,
                    'currency_code' => $paymentCurrencyCode,
                    'exchange_rate' => $exchangeRate,
                    'amount_in_document_currency' => $amountInDocCurrency,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'payment_date' => $data['payment_date'],
                    'reference' => $data['reference'] ?? null,
                    'status' => PaymentStatus::PENDING_APPROVAL,
                    'notes' => $data['notes'] ?? null,
                    'attachment_path' => $data['attachment_path'] ?? null,
                ]);

                Notification::make()
                    ->title('Payment recorded — pending approval')
                    ->success()
                    ->send();
            });
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
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
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
            ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
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
