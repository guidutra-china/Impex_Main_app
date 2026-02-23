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

                        $allocations = $record->allocations()
                            ->whereHas('payment', fn ($q) => $q->whereNot('status', PaymentStatus::CANCELLED))
                            ->with('payment')
                            ->get();

                        if ($allocations->isEmpty()) {
                            return '—';
                        }

                        return $allocations->map(function ($alloc) {
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

                            return "{$statusBadge} {$date} — {$currency} {$amount}{$ref}";
                        })->join("\n");
                    }),
            ])
            ->defaultSort('sort_order')
            ->contentFooter(fn () => new HtmlString($this->renderPaymentSummary()))
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

    protected function renderPaymentSummary(): string
    {
        $record = $this->getOwnerRecord();
        $items = PaymentScheduleItem::query()
            ->where('payable_type', get_class($record))
            ->where('payable_id', $record->getKey())
            ->get();

        $currency = $record->currency_code ?? 'USD';

        $totalDue = $items->where('is_credit', false)->sum('amount');
        $totalCredits = $items->where('is_credit', true)->sum('amount');
        $netDue = $totalDue - abs($totalCredits);
        $totalPaid = $items->where('is_credit', false)->sum(fn ($i) => $i->paid_amount);
        $netRemaining = $netDue - $totalPaid;

        $html = '<div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">';
        $html .= '<div class="flex justify-end gap-8 text-sm">';

        $html .= '<div class="text-right">';
        $html .= '<span class="text-gray-500 dark:text-gray-400">Total Due:</span> ';
        $html .= '<span class="font-semibold">' . $currency . ' ' . Money::format($totalDue) . '</span>';
        $html .= '</div>';

        if (abs($totalCredits) > 0) {
            $html .= '<div class="text-right">';
            $html .= '<span class="text-gray-500 dark:text-gray-400">Credits:</span> ';
            $html .= '<span class="font-semibold text-info-600">(' . $currency . ' ' . Money::format(abs($totalCredits)) . ')</span>';
            $html .= '</div>';

            $html .= '<div class="text-right">';
            $html .= '<span class="text-gray-500 dark:text-gray-400">Net Due:</span> ';
            $html .= '<span class="font-semibold">' . $currency . ' ' . Money::format($netDue) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div class="text-right">';
        $html .= '<span class="text-gray-500 dark:text-gray-400">Paid:</span> ';
        $html .= '<span class="font-semibold text-success-600">' . $currency . ' ' . Money::format($totalPaid) . '</span>';
        $html .= '</div>';

        $html .= '<div class="text-right">';
        $html .= '<span class="text-gray-500 dark:text-gray-400">Remaining:</span> ';
        $remainingColor = $netRemaining > 0 ? 'text-warning-600' : 'text-success-600';
        $html .= '<span class="font-semibold ' . $remainingColor . '">' . $currency . ' ' . Money::format(abs($netRemaining)) . '</span>';
        $html .= '</div>';

        $html .= '</div></div>';

        return $html;
    }

    protected function renderAllocationsDetail(PaymentScheduleItem $item): string
    {
        $allocations = $item->allocations()
            ->whereHas('payment', fn ($q) => $q->whereNot('status', PaymentStatus::CANCELLED))
            ->with(['payment.paymentMethod', 'payment.company'])
            ->get();

        if ($allocations->isEmpty()) {
            return '<p class="text-sm text-gray-500 py-4">No deposits recorded for this schedule item.</p>';
        }

        $html = '<div class="overflow-x-auto">';
        $html .= '<table class="w-full text-sm border-collapse">';
        $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Date</th>';
        $html .= '<th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Amount</th>';
        $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Currency</th>';
        $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Method</th>';
        $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Reference</th>';
        $html .= '<th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Status</th>';
        $html .= '<th class="text-center py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Action</th>';
        $html .= '</tr></thead><tbody>';

        $totalAllocated = 0;

        foreach ($allocations as $alloc) {
            $payment = $alloc->payment;
            $date = $payment->payment_date?->format('d/m/Y') ?? '—';
            $amount = Money::format($alloc->allocated_amount);
            $currency = $payment->currency_code;
            $method = $payment->paymentMethod?->name ?? '—';
            $ref = e($payment->reference ?? '—');
            $totalAllocated += $alloc->allocated_amount;

            $statusColor = match ($payment->status) {
                PaymentStatus::APPROVED => 'text-green-600',
                PaymentStatus::PENDING_APPROVAL => 'text-yellow-600',
                PaymentStatus::REJECTED => 'text-red-600',
                default => 'text-gray-500',
            };
            $statusLabel = $payment->status instanceof BackedEnum ? ucfirst($payment->status->value) : $payment->status;

            $viewUrl = PaymentResource::getUrl('view', ['record' => $payment]);

            $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
            $html .= "<td class=\"py-2 px-3\">{$date}</td>";
            $html .= "<td class=\"py-2 px-3 text-right font-medium\">{$amount}</td>";
            $html .= "<td class=\"py-2 px-3\">{$currency}</td>";
            $html .= "<td class=\"py-2 px-3\">{$method}</td>";
            $html .= "<td class=\"py-2 px-3\">{$ref}</td>";
            $html .= "<td class=\"py-2 px-3 {$statusColor} font-medium\">{$statusLabel}</td>";
            $html .= "<td class=\"py-2 px-3 text-center\"><a href=\"{$viewUrl}\" target=\"_blank\" class=\"text-primary-600 hover:underline text-xs\">View</a></td>";
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
        $html .= '<td class="py-2 px-3 font-bold text-right">Total:</td>';
        $html .= '<td class="py-2 px-3 text-right font-bold">' . Money::format($totalAllocated) . '</td>';
        $html .= '<td colspan="5"></td>';
        $html .= '</tr></tfoot>';
        $html .= '</table></div>';

        $remaining = $item->remaining_amount;
        if ($remaining > 0) {
            $html .= '<div class="mt-3 p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded text-sm text-yellow-700 dark:text-yellow-400">';
            $html .= 'Remaining: ' . $item->currency_code . ' ' . Money::format($remaining);
            $html .= '</div>';
        } else {
            $html .= '<div class="mt-3 p-2 bg-green-50 dark:bg-green-900/20 rounded text-sm text-green-700 dark:text-green-400">';
            $html .= 'Fully paid';
            $html .= '</div>';
        }

        return $html;
    }
}
