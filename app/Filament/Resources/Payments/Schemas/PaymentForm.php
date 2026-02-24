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
            Section::make('Payment Information')->columns(2)->schema([
                Select::make('direction')
                    ->label('Direction')
                    ->options(PaymentDirection::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('company_id', null);
                        $set('allocations', []);
                        $set('credit_applications', []);
                        $set('amount', null);
                    }),
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
                        $set('credit_applications', []);
                        $set('amount', null);
                    }),
                Select::make('currency_code')
                    ->label('Payment Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->required()
                    ->live(),
                TextInput::make('amount')
                    ->label('Wire Transfer Amount')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Actual amount transferred. Credits are applied separately.'),
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

            Section::make('Outstanding Items & Credits')
                ->description('Overview of pending schedule items and available credits for the selected company.')
                ->visible(fn (Get $get) => filled($get('company_id')))
                ->collapsible()
                ->schema([
                    Placeholder::make('combined_overview')
                        ->label('')
                        ->content(function (Get $get) {
                            $companyId = (int) $get('company_id');
                            $direction = $get('direction');

                            if (! $companyId) {
                                return 'Select a company to see outstanding items.';
                            }

                            $items = static::getCompanyScheduleItems($companyId, $direction);
                            $credits = static::getCompanyCreditItems($companyId, $direction);

                            if ($items->isEmpty() && $credits->isEmpty()) {
                                return new HtmlString('<p class="text-sm text-gray-500">No pending schedule items or credits for this company.</p>');
                            }

                            $html = '';

                            if ($items->isNotEmpty()) {
                                $html .= static::buildOutstandingTable($items);
                            }

                            if ($credits->isNotEmpty()) {
                                if ($items->isNotEmpty()) {
                                    $html .= '<div class="my-3 border-t border-gray-200 dark:border-gray-700"></div>';
                                }
                                $html .= static::buildCreditsTable($credits);
                            }

                            return new HtmlString($html);
                        }),
                ]),

            Section::make('Allocations')
                ->description('Allocate the wire transfer amount to schedule items.')
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
                                ->columnSpan(5),
                            TextInput::make('allocated_amount')
                                ->label('Amount')
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
                                ->placeholder('Auto')
                                ->columnSpan(2),
                        ])
                        ->columns(10)
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

                            $parts = ['<span class="font-semibold">Allocated: ' . number_format($total, 2) . '</span>'];
                            if ($amount > 0 && abs($unallocated) > 0.001) {
                                $color = $unallocated > 0 ? 'text-yellow-600' : 'text-red-600';
                                $label = $unallocated > 0 ? 'Unallocated' : 'Over-allocated';
                                $parts[] = '<span class="' . $color . ' font-medium"> | ' . $label . ': ' . number_format(abs($unallocated), 2) . '</span>';
                            }
                            return new HtmlString(implode('', $parts));
                        }),
                ]),

            Section::make('Credit Applications')
                ->description('Apply credits to offset schedule item balances. This does not affect the wire transfer amount.')
                ->visible(fn (Get $get) => filled($get('company_id'))
                    && static::getCompanyCreditItems((int) $get('company_id'), $get('direction'))->isNotEmpty())
                ->collapsed()
                ->schema([
                    Repeater::make('credit_applications')
                        ->label('')
                        ->schema([
                            Select::make('credit_schedule_item_id')
                                ->label('Credit')
                                ->options(function (Get $get) {
                                    $companyId = $get('../../company_id');
                                    $direction = $get('../../direction');
                                    if (! $companyId) {
                                        return [];
                                    }
                                    return static::getCompanyCreditItems((int) $companyId, $direction)
                                        ->mapWithKeys(fn ($item) => [
                                            $item->id => static::formatCreditItemLabel($item),
                                        ]);
                                })
                                ->required()
                                ->distinct()
                                ->searchable()
                                ->columnSpan(4),
                            Select::make('payment_schedule_item_id')
                                ->label('Apply to')
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
                                ->searchable()
                                ->columnSpan(4),
                            TextInput::make('credit_amount')
                                ->label('Amount')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0.01)
                                ->required()
                                ->columnSpan(2),
                        ])
                        ->columns(10)
                        ->defaultItems(0)
                        ->addActionLabel('+ Apply Credit')
                        ->live()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    protected static function buildOutstandingTable(\Illuminate\Support\Collection $items): string
    {
        $grouped = $items->groupBy(fn ($item) => $item->payable?->reference ?? 'Unknown');

        $html = '<div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Outstanding Items</div>';
        $html .= '<table class="w-full text-sm border-collapse">';
        $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Document</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Stage</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Due</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Paid</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Remaining</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Cur.</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Status</th>';
        $html .= '</tr></thead><tbody>';

        $totalRemaining = 0;

        foreach ($grouped as $docRef => $docItems) {
            foreach ($docItems as $item) {
                $remaining = $item->remaining_amount;
                $totalRemaining += $remaining;
                $statusColor = match ($item->status->value ?? $item->status) {
                    'due' => 'text-yellow-600',
                    'overdue' => 'text-red-600',
                    default => 'text-gray-500',
                };
                $statusLabel = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;

                $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
                $html .= '<td class="py-1.5 px-2 font-medium">' . e($docRef) . '</td>';
                $html .= '<td class="py-1.5 px-2">' . e($item->label) . '</td>';
                $html .= '<td class="py-1.5 px-2 text-right">' . Money::format($item->amount) . '</td>';
                $html .= '<td class="py-1.5 px-2 text-right">' . Money::format($item->paid_amount) . '</td>';
                $html .= '<td class="py-1.5 px-2 text-right font-semibold">' . Money::format($remaining) . '</td>';
                $html .= '<td class="py-1.5 px-2">' . e($item->currency_code) . '</td>';
                $html .= '<td class="py-1.5 px-2 ' . $statusColor . ' capitalize">' . e($statusLabel) . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody>';
        $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
        $html .= '<td colspan="4" class="py-1.5 px-2 font-bold text-right">Total Remaining:</td>';
        $html .= '<td class="py-1.5 px-2 text-right font-bold text-primary-600">' . Money::format($totalRemaining) . '</td>';
        $html .= '<td colspan="2"></td>';
        $html .= '</tr></tfoot></table>';

        return $html;
    }

    protected static function buildCreditsTable(\Illuminate\Support\Collection $credits): string
    {
        $html = '<div class="text-xs font-semibold text-green-600 dark:text-green-400 uppercase tracking-wider mb-2">Available Credits</div>';
        $html .= '<table class="w-full text-sm border-collapse">';
        $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Document</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Description</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Credit Amount</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Cur.</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($credits as $credit) {
            $docRef = $credit->payable?->reference ?? 'Unknown';
            $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
            $html .= '<td class="py-1.5 px-2 font-medium">' . e($docRef) . '</td>';
            $html .= '<td class="py-1.5 px-2">' . e($credit->label) . '</td>';
            $html .= '<td class="py-1.5 px-2 text-right font-semibold text-green-600">' . Money::format($credit->amount) . '</td>';
            $html .= '<td class="py-1.5 px-2">' . e($credit->currency_code) . '</td>';
            $html .= '</tr>';
        }

        $totalCredit = $credits->sum('amount');
        $html .= '</tbody>';
        $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
        $html .= '<td colspan="2" class="py-1.5 px-2 font-bold text-right">Total Credit:</td>';
        $html .= '<td class="py-1.5 px-2 text-right font-bold text-green-600">' . Money::format($totalCredit) . '</td>';
        $html .= '<td></td>';
        $html .= '</tr></tfoot></table>';

        return $html;
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
        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

        $query = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
            $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');
            $query->where('payable_type', ProformaInvoice::class)->whereIn('payable_id', $piIds);
        } else {
            $poIds = PurchaseOrder::where('supplier_company_id', $companyId)->pluck('id');
            $query->where('payable_type', PurchaseOrder::class)->whereIn('payable_id', $poIds);
        }

        return $query->with('payable')->get();
    }

    public static function getCompanyCreditItems(int $companyId, mixed $direction): \Illuminate\Support\Collection
    {
        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

        $query = PaymentScheduleItem::query()
            ->where('is_credit', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
            $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');
            $query->where('payable_type', ProformaInvoice::class)->whereIn('payable_id', $piIds);
        } else {
            $poIds = PurchaseOrder::where('supplier_company_id', $companyId)->pluck('id');
            $query->where('payable_type', PurchaseOrder::class)->whereIn('payable_id', $poIds);
        }

        return $query->with('payable')->get();
    }

    protected static function formatScheduleItemLabel(PaymentScheduleItem $item): string
    {
        $docRef = $item->payable?->reference ?? 'Unknown';
        $remaining = Money::format($item->remaining_amount);

        return "[{$docRef}] {$item->label} — {$item->currency_code} {$remaining} remaining";
    }

    protected static function formatCreditItemLabel(PaymentScheduleItem $item): string
    {
        $docRef = $item->payable?->reference ?? 'Unknown';
        $amount = Money::format($item->amount);

        return "[{$docRef}] {$item->label} — {$item->currency_code} {$amount} credit";
    }
}
