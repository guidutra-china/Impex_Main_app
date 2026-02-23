<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Context')->columns(3)->schema([
                Select::make('direction')
                    ->label('Direction')
                    ->options(PaymentDirection::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('company_id', null);
                        $set('allocations', []);
                        $set('amount', null);
                    })
                    ->columnSpan(1),
                Select::make('company_id')
                    ->label('Company')
                    ->options(function (Get $get) {
                        $direction = $get('direction');

                        if (! $direction) {
                            return [];
                        }

                        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

                        $query = Company::query();

                        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', 'client'));
                        } else {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', 'supplier'));
                        }

                        return $query->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('allocations', []);
                        $set('amount', null);
                    })
                    ->columnSpan(1),
                Select::make('currency_code')
                    ->label('Payment Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->required()
                    ->live()
                    ->columnSpan(1),
            ]),

            Section::make('Outstanding Schedule Items')
                ->description('All pending schedule items for the selected company. Use this as reference when adding allocations below.')
                ->visible(fn (Get $get) => filled($get('company_id')))
                ->schema([
                    Placeholder::make('pending_items_table')
                        ->label('')
                        ->content(function (Get $get) {
                            $companyId = $get('company_id');
                            $direction = $get('direction');

                            if (! $companyId) {
                                return 'Select a company to see outstanding items.';
                            }

                            $items = static::getCompanyScheduleItems((int) $companyId, $direction);

                            if ($items->isEmpty()) {
                                return new HtmlString('<p class="text-sm text-gray-500">No pending schedule items for this company.</p>');
                            }

                            $grouped = $items->groupBy(fn ($item) => $item->payable?->reference ?? 'Unknown');

                            $html = '<div class="overflow-x-auto">';
                            $html .= '<table class="w-full text-sm border-collapse">';
                            $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
                            $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Document</th>';
                            $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Stage</th>';
                            $html .= '<th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Total Due</th>';
                            $html .= '<th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Already Paid</th>';
                            $html .= '<th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Remaining</th>';
                            $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Currency</th>';
                            $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Status</th>';
                            $html .= '</tr></thead><tbody>';

                            $totalRemaining = 0;

                            foreach ($grouped as $docRef => $docItems) {
                                foreach ($docItems as $item) {
                                    $remaining = $item->remaining_amount;
                                    $totalRemaining += $remaining;
                                    $paidAmount = $item->paid_amount;
                                    $statusColor = match ($item->status->value ?? $item->status) {
                                        'due' => 'text-yellow-600',
                                        'overdue' => 'text-red-600',
                                        'pending' => 'text-gray-500',
                                        'partial' => 'text-blue-600',
                                        default => 'text-gray-500',
                                    };

                                    $statusLabel = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;

                                    $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
                                    $html .= '<td class="py-2 px-3 font-medium">' . e($docRef) . '</td>';
                                    $html .= '<td class="py-2 px-3">' . e($item->label) . '</td>';
                                    $html .= '<td class="py-2 px-3 text-right">' . Money::format($item->amount) . '</td>';
                                    $html .= '<td class="py-2 px-3 text-right">' . Money::format($paidAmount) . '</td>';
                                    $html .= '<td class="py-2 px-3 text-right font-semibold">' . Money::format($remaining) . '</td>';
                                    $html .= '<td class="py-2 px-3">' . e($item->currency_code) . '</td>';
                                    $html .= '<td class="py-2 px-3 ' . $statusColor . ' capitalize">' . e($statusLabel) . '</td>';
                                    $html .= '</tr>';
                                }
                            }

                            $html .= '</tbody>';
                            $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
                            $html .= '<td colspan="4" class="py-2 px-3 font-bold text-right">Total Remaining:</td>';
                            $html .= '<td class="py-2 px-3 text-right font-bold text-primary-600">' . Money::format($totalRemaining) . '</td>';
                            $html .= '<td colspan="2"></td>';
                            $html .= '</tr></tfoot>';
                            $html .= '</table></div>';

                            return new HtmlString($html);
                        }),
                ]),

            Section::make('Allocations')
                ->description('Add allocations first. The total payment amount will be calculated automatically from the sum of allocations.')
                ->visible(fn (Get $get) => filled($get('company_id')))
                ->schema([
                    Repeater::make('allocations')
                        ->label('')
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
                                ->columnSpan(4),
                            TextInput::make('allocated_amount')
                                ->label('Amount to Allocate')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0.01)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    static::recalculateTotal($get, $set);
                                })
                                ->columnSpan(3),
                            TextInput::make('exchange_rate')
                                ->label('Exchange Rate')
                                ->numeric()
                                ->step('0.00000001')
                                ->placeholder('Auto / Same currency')
                                ->columnSpan(2),
                        ])
                        ->columns(9)
                        ->defaultItems(0)
                        ->addActionLabel('+ Add Allocation')
                        ->deleteAction(fn ($action) => $action->after(function (Get $get, Set $set) {
                            static::recalculateTotal($get, $set);
                        }))
                        ->live()
                        ->columnSpanFull(),

                    Placeholder::make('allocation_summary')
                        ->label('')
                        ->content(function (Get $get) {
                            $allocations = $get('allocations') ?? [];
                            $total = 0;

                            foreach ($allocations as $alloc) {
                                $total += (float) ($alloc['allocated_amount'] ?? 0);
                            }

                            $amount = (float) ($get('amount') ?? 0);
                            $unallocated = $amount - $total;

                            $parts = [];
                            $parts[] = '<span class="font-semibold">Total Allocated: ' . number_format($total, 2) . '</span>';

                            if ($amount > 0 && abs($unallocated) > 0.001) {
                                if ($unallocated > 0) {
                                    $parts[] = '<span class="text-yellow-600 font-medium"> | Unallocated: ' . number_format($unallocated, 2) . ' (will be credit)</span>';
                                } else {
                                    $parts[] = '<span class="text-red-600 font-medium"> | Over-allocated by: ' . number_format(abs($unallocated), 2) . '</span>';
                                }
                            }

                            return new HtmlString(implode('', $parts));
                        }),
                ]),

            Section::make('Payment Details')->columns(2)->schema([
                TextInput::make('amount')
                    ->label('Total Payment Amount')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Auto-calculated from allocations. Adjust if payment includes unallocated credit.'),
                DatePicker::make('payment_date')
                    ->label('Payment Date')
                    ->default(now())
                    ->required(),
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
        ]);
    }

    protected static function recalculateTotal(Get $get, Set $set): void
    {
        $allocations = $get('../../allocations') ?? $get('allocations') ?? [];
        $total = 0;

        foreach ($allocations as $alloc) {
            $total += (float) ($alloc['allocated_amount'] ?? 0);
        }

        if ($total > 0) {
            $set('../../amount', number_format($total, 2, '.', ''));
        }
    }

    public static function getCompanyScheduleItems(int $companyId, mixed $direction): \Illuminate\Support\Collection
    {
        $piType = ProformaInvoice::class;
        $poType = PurchaseOrder::class;

        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

        $query = PaymentScheduleItem::query()
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
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
        $currency = $item->currency_code;

        return "[{$docRef}] {$item->label} — {$currency} {$remaining} remaining";
    }
}
