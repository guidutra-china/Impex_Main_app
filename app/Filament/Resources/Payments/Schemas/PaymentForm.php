<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentMethod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Details')->columns(2)->schema([
                Select::make('direction')
                    ->label('Direction')
                    ->options(PaymentDirection::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('company_id', null)),
                Select::make('company_id')
                    ->label('Company')
                    ->options(function (Get $get) {
                        $direction = $get('direction');

                        if (! $direction) {
                            return [];
                        }

                        $query = Company::query();

                        if ($direction === PaymentDirection::INBOUND->value || $direction === 'inbound') {
                            $query->whereHas('roleAssignments', fn ($q) => $q->where('role', 'client'));
                        } else {
                            $query->whereHas('roleAssignments', fn ($q) => $q->where('role', 'supplier'));
                        }

                        return $query->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->live(),
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

            Section::make('Allocations')
                ->description('Distribute this payment across schedule items from any PI or PO of the selected company.')
                ->schema([
                    Placeholder::make('allocation_hint')
                        ->label('')
                        ->content(function (Get $get) {
                            $companyId = $get('company_id');
                            $amount = $get('amount');

                            if (! $companyId) {
                                return 'Select a company first to see available schedule items.';
                            }

                            $pendingItems = static::getCompanyScheduleItems((int) $companyId, $get('direction'));
                            $totalRemaining = $pendingItems->sum('remaining_amount');

                            $parts = [];
                            $parts[] = $pendingItems->count() . ' pending schedule item(s) for this company.';
                            $parts[] = 'Total remaining: ' . Money::format($totalRemaining);

                            if ($amount) {
                                $amountMinor = Money::toMinor((float) $amount);
                                $diff = $amountMinor - $totalRemaining;

                                if ($diff > 0) {
                                    $parts[] = '⚠ Payment exceeds total remaining by ' . Money::format($diff) . ' (will be unallocated credit).';
                                }
                            }

                            return implode(' ', $parts);
                        }),
                    Repeater::make('allocations')
                        ->label('Allocations')
                        ->relationship('allocations')
                        ->schema([
                            Select::make('payment_schedule_item_id')
                                ->label('Schedule Item')
                                ->options(function (Get $get) {
                                    $companyId = $get('../../company_id');
                                    $direction = $get('../../direction');

                                    if (! $companyId) {
                                        return [];
                                    }

                                    return static::getCompanyScheduleItems((int) $companyId, $direction)
                                        ->mapWithKeys(fn ($item) => [
                                            $item->id => static::formatScheduleItemLabel($item),
                                        ]);
                                })
                                ->required()
                                ->distinct()
                                ->searchable()
                                ->columnSpan(3),
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
                        ->columns(5)
                        ->defaultItems(1)
                        ->addActionLabel('Add allocation')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getCompanyScheduleItems(int $companyId, ?string $direction): \Illuminate\Support\Collection
    {
        $piType = ProformaInvoice::class;
        $poType = PurchaseOrder::class;

        $query = PaymentScheduleItem::query()
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        if ($direction === PaymentDirection::INBOUND->value || $direction === 'inbound') {
            $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');
            $query->where('payable_type', $piType)->whereIn('payable_id', $piIds);
        } else {
            $poIds = PurchaseOrder::where('supplier_company_id', $companyId)->pluck('id');
            $query->where('payable_type', $poType)->whereIn('payable_id', $poIds);
        }

        return $query->with('payable')->get();
    }

    protected static function formatScheduleItemLabel(PaymentScheduleItem $item): string
    {
        $payable = $item->payable;
        $docRef = $payable?->reference ?? 'Unknown';
        $remaining = Money::format($item->remaining_amount);

        return "[{$docRef}] {$item->label} — {$item->currency_code} {$remaining} remaining";
    }
}
