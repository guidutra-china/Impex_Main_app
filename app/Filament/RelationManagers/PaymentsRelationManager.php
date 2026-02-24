<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Payments\PaymentResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentScheduleItems';

    protected static ?string $title = 'Payments';

    protected static BackedEnum|string|null $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        $summaryData = $this->getPaymentSummaryData();

        return $table
            ->query(fn () => $this->getScheduleItemsQuery())
            ->columns([
                TextColumn::make('label')
                    ->label('Schedule Stage')
                    ->weight('bold')
                    ->icon(fn ($record) => $record->is_credit ? 'heroicon-o-arrow-uturn-left' : null)
                    ->color(fn ($record) => $record->is_credit ? 'info' : null)
                    ->description(fn ($record) => $record->is_credit ? 'Credit' : null)
                    ->searchable(),
                TextColumn::make('percentage')
                    ->label('%')
                    ->suffix('%')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(fn () => 'Due Amount')
                    ->formatStateUsing(function ($state, $record) {
                        $formatted = $record->currency_code . ' ' . Money::format(abs($state));
                        return $record->is_credit ? "({$formatted})" : $formatted;
                    })
                    ->color(fn ($record) => $record->is_credit ? 'info' : null)
                    ->alignEnd(),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->getStateUsing(fn ($record) => $record->paid_amount)
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code . ' ' . Money::format($state))
                    ->alignEnd()
                    ->color(fn ($record) => $record->is_paid_in_full ? 'success' : ($record->paid_amount > 0 ? 'info' : 'gray'))
                    ->visible(fn () => ! $this->hasOnlyCredits()),
                TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->getStateUsing(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code . ' ' . Money::format(abs($state)))
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->visible(fn () => ! $this->hasOnlyCredits()),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('allocations_detail')
                    ->label('Deposits')
                    ->wrap()
                    ->getStateUsing(function ($record) {
                        if ($record->is_credit) {
                            $source = $record->source;
                            if ($source) {
                                return "Credit from: {$source->description}";
                            }
                            return 'Credit';
                        }

                        $regularAllocations = $record->allocations()
                            ->whereNull('credit_schedule_item_id')
                            ->whereHas('payment', fn ($q) => $q->whereNot('status', PaymentStatus::CANCELLED))
                            ->with('payment')
                            ->get();

                        $creditAllocations = $record->allocations()
                            ->whereNotNull('credit_schedule_item_id')
                            ->with(['payment', 'creditItem'])
                            ->get();

                        $lines = collect();

                        foreach ($regularAllocations as $alloc) {
                            $payment = $alloc->payment;
                            $date = $payment->payment_date?->format('d/m/Y') ?? '—';
                            $amount = Money::format($alloc->allocated_amount);
                            $currency = $payment->currency_code;
                            $ref = $payment->reference ? " ({$payment->reference})" : '';
                            $statusBadge = match ($payment->status) {
                                PaymentStatus::APPROVED => '✓',
                                PaymentStatus::PENDING_APPROVAL => '⏳',
                                PaymentStatus::REJECTED => '✗',
                                default => '',
                            };
                            $lines->push("{$statusBadge} {$date} — {$currency} {$amount}{$ref}");
                        }

                        foreach ($creditAllocations as $alloc) {
                            $creditLabel = $alloc->creditItem?->label ?? 'Credit';
                            $creditAmount = Money::format($alloc->allocated_amount_in_document_currency);
                            $currency = $record->currency_code;
                            $lines->push("↩ Credit: {$currency} {$creditAmount} ({$creditLabel})");
                        }

                        return $lines->isEmpty() ? '—' : $lines->join("\n");
                    }),
            ])
            ->defaultSort('sort_order')
            ->contentFooter(
                view('filament.payments.schedule-footer', $summaryData)
            )
            ->headerActions([
                Action::make('recordPayment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->url(fn () => PaymentResource::getUrl('create'))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('viewPayments')
                    ->label('View Deposits')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn ($record) => ! $record->is_credit)
                    ->modalHeading(fn ($record) => "Deposits for: {$record->label}")
                    ->modalWidth('3xl')
                    ->modalContent(fn ($record) => new HtmlString($this->renderAllocationsDetail($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    protected function getScheduleItemsQuery(): Builder
    {
        $record = $this->getOwnerRecord();

        return PaymentScheduleItem::query()
            ->where('payable_type', get_class($record))
            ->where('payable_id', $record->getKey())
            ->orderBy('is_credit')
            ->orderBy('sort_order');
    }

    protected function hasOnlyCredits(): bool
    {
        $record = $this->getOwnerRecord();

        return PaymentScheduleItem::query()
            ->where('payable_type', get_class($record))
            ->where('payable_id', $record->getKey())
            ->where('is_credit', false)
            ->doesntExist();
    }

    protected function getPaymentSummaryData(): array
    {
        $record = $this->getOwnerRecord();
        $items = PaymentScheduleItem::query()
            ->where('payable_type', get_class($record))
            ->where('payable_id', $record->getKey())
            ->get();

        $currency = $record->currency_code ?? 'USD';

        $totalDueRaw = $items->where('is_credit', false)->sum('amount');
        $totalCreditsRaw = $items->where('is_credit', true)->sum(fn ($i) => abs($i->amount));
        $netDueRaw = $totalDueRaw - $totalCreditsRaw;
        $totalPaidRaw = $items->where('is_credit', false)->sum(fn ($i) => $i->paid_amount);
        $netRemainingRaw = $netDueRaw - $totalPaidRaw;

        return [
            'currency' => $currency,
            'totalDue' => Money::format($totalDueRaw),
            'totalCredits' => $totalCreditsRaw,
            'totalCreditsFormatted' => Money::format($totalCreditsRaw),
            'netDueFormatted' => Money::format($netDueRaw),
            'totalPaidFormatted' => Money::format($totalPaidRaw),
            'netRemaining' => $netRemainingRaw,
            'netRemainingFormatted' => Money::format(abs($netRemainingRaw)),
        ];
    }

    protected function renderAllocationsDetail(PaymentScheduleItem $item): string
    {
        $regularAllocations = $item->allocations()
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->whereNot('status', PaymentStatus::CANCELLED))
            ->with(['payment.paymentMethod'])
            ->get();

        $creditAllocations = $item->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->with(['creditItem'])
            ->get();

        if ($regularAllocations->isEmpty() && $creditAllocations->isEmpty()) {
            return '<p class="text-sm text-gray-500 py-4">No deposits recorded for this schedule item.</p>';
        }

        $html = '<div style="min-width: 600px;" class="space-y-6 py-2">';

        if ($regularAllocations->isNotEmpty()) {
            $html .= '<div>';
            $html .= '<div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Payments</div>';
            $html .= '<table class="w-full text-sm border-collapse" style="table-layout: fixed;">';
            $html .= '<colgroup>';
            $html .= '<col style="width: 15%;">';
            $html .= '<col style="width: 18%;">';
            $html .= '<col style="width: 10%;">';
            $html .= '<col style="width: 17%;">';
            $html .= '<col style="width: 17%;">';
            $html .= '<col style="width: 14%;">';
            $html .= '<col style="width: 9%;">';
            $html .= '</colgroup>';
            $html .= '<thead><tr class="border-b-2 border-gray-200 dark:border-gray-700">';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Date</th>';
            $html .= '<th class="text-right py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Amount</th>';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Cur.</th>';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Method</th>';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Reference</th>';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Status</th>';
            $html .= '<th class="text-center py-3 px-4 font-bold text-gray-600 dark:text-gray-400"></th>';
            $html .= '</tr></thead><tbody>';

            $totalPayments = 0;

            foreach ($regularAllocations as $alloc) {
                $payment = $alloc->payment;
                $date = $payment->payment_date?->format('d/m/Y') ?? '—';
                $amount = Money::format($alloc->allocated_amount);
                $currency = $payment->currency_code;
                $method = $payment->paymentMethod?->name ?? '—';
                $ref = e($payment->reference ?? '—');
                $totalPayments += $alloc->allocated_amount;

                $statusColor = match ($payment->status) {
                    PaymentStatus::APPROVED => 'text-green-600',
                    PaymentStatus::PENDING_APPROVAL => 'text-yellow-600',
                    PaymentStatus::REJECTED => 'text-red-600',
                    default => 'text-gray-500',
                };
                $statusLabel = $payment->status instanceof BackedEnum ? ucfirst($payment->status->value) : $payment->status;
                $viewUrl = PaymentResource::getUrl('view', ['record' => $payment]);

                $html .= '<tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">';
                $html .= "<td class=\"py-3 px-4\">{$date}</td>";
                $html .= "<td class=\"py-3 px-4 text-right font-semibold\">{$amount}</td>";
                $html .= "<td class=\"py-3 px-4\">{$currency}</td>";
                $html .= "<td class=\"py-3 px-4\">{$method}</td>";
                $html .= "<td class=\"py-3 px-4\">{$ref}</td>";
                $html .= "<td class=\"py-3 px-4 {$statusColor} font-semibold\">{$statusLabel}</td>";
                $html .= "<td class=\"py-3 px-4 text-center\"><a href=\"{$viewUrl}\" target=\"_blank\" class=\"text-primary-600 hover:underline font-medium\">View</a></td>";
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
            $html .= '<td class="py-3 px-4 font-bold text-right">Subtotal:</td>';
            $html .= '<td class="py-3 px-4 text-right font-bold">' . Money::format($totalPayments) . '</td>';
            $html .= '<td colspan="5"></td>';
            $html .= '</tr></tfoot></table></div>';
        }

        if ($creditAllocations->isNotEmpty()) {
            $html .= '<div>';
            $html .= '<div class="text-xs font-bold text-green-600 dark:text-green-400 uppercase tracking-wider mb-3">Credits Applied</div>';
            $html .= '<table class="w-full text-sm border-collapse" style="table-layout: fixed;">';
            $html .= '<colgroup>';
            $html .= '<col style="width: 55%;">';
            $html .= '<col style="width: 30%;">';
            $html .= '<col style="width: 15%;">';
            $html .= '</colgroup>';
            $html .= '<thead><tr class="border-b-2 border-gray-200 dark:border-gray-700">';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Credit Source</th>';
            $html .= '<th class="text-right py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Amount</th>';
            $html .= '<th class="text-left py-3 px-4 font-bold text-gray-600 dark:text-gray-400">Cur.</th>';
            $html .= '</tr></thead><tbody>';

            $totalCredits = 0;

            foreach ($creditAllocations as $alloc) {
                $creditLabel = e($alloc->creditItem?->label ?? 'Credit');
                $creditAmount = $alloc->allocated_amount_in_document_currency;
                $totalCredits += $creditAmount;

                $html .= '<tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">';
                $html .= "<td class=\"py-3 px-4 text-green-600 font-semibold\">{$creditLabel}</td>";
                $html .= '<td class="py-3 px-4 text-right font-semibold text-green-600">' . Money::format($creditAmount) . '</td>';
                $html .= '<td class="py-3 px-4">' . e($item->currency_code) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
            $html .= '<td class="py-3 px-4 font-bold text-right">Subtotal:</td>';
            $html .= '<td class="py-3 px-4 text-right font-bold text-green-600">' . Money::format($totalCredits) . '</td>';
            $html .= '<td></td>';
            $html .= '</tr></tfoot></table></div>';
        }

        $html .= '</div>';

        $remaining = $item->remaining_amount;
        $totalPaid = $regularAllocations->sum('allocated_amount');
        $totalCreditApplied = $creditAllocations->sum('allocated_amount_in_document_currency');

        $html .= '<div class="mt-4 p-4 rounded-lg border ' . ($remaining > 0
            ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800'
            : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800') . '">';
        $html .= '<div class="flex items-center justify-between gap-x-8">';
        $html .= '<span class="text-sm"><span class="font-bold text-gray-700 dark:text-gray-300">Due:</span> <span class="font-bold">' . $item->currency_code . ' ' . Money::format($item->amount) . '</span></span>';
        $html .= '<span class="text-sm"><span class="font-bold text-gray-700 dark:text-gray-300">Paid:</span> <span class="font-bold text-blue-600">' . $item->currency_code . ' ' . Money::format($totalPaid) . '</span></span>';
        if ($totalCreditApplied > 0) {
            $html .= '<span class="text-sm"><span class="font-bold text-gray-700 dark:text-gray-300">Credits:</span> <span class="font-bold text-green-600">' . $item->currency_code . ' ' . Money::format($totalCreditApplied) . '</span></span>';
        }
        $html .= '<span class="text-sm"><span class="font-bold ' . ($remaining > 0 ? 'text-yellow-700 dark:text-yellow-400' : 'text-green-700 dark:text-green-400') . '">';
        $html .= $remaining > 0
            ? 'Remaining: ' . $item->currency_code . ' ' . Money::format($remaining)
            : 'Fully Paid';
        $html .= '</span></span></div></div>';

        return $html;
    }
}
