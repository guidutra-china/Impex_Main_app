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
                    ->label(__('forms.labels.schedule_stage'))
                    ->weight('bold')
                    ->icon(fn ($record) => $record->is_credit ? 'heroicon-o-arrow-uturn-left' : null)
                    ->color(fn ($record) => $record->is_credit ? 'info' : null)
                    ->description(fn ($record) => $record->is_credit ? 'Credit' : null)
                    ->searchable(),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
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
                    ->label(__('forms.labels.paid'))
                    ->getStateUsing(fn ($record) => $record->paid_amount)
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code . ' ' . Money::format($state))
                    ->alignEnd()
                    ->color(fn ($record) => $record->is_paid_in_full ? 'success' : ($record->paid_amount > 0 ? 'info' : 'gray'))
                    ->visible(fn () => ! $this->hasOnlyCredits()),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining'))
                    ->getStateUsing(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code . ' ' . Money::format(abs($state)))
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->visible(fn () => ! $this->hasOnlyCredits()),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('allocations_detail')
                    ->label(__('forms.labels.deposits'))
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
                    ->label(__('forms.labels.record_payment'))
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->can('create-payments'))
                    ->url(function () {
                        $owner = $this->getOwnerRecord();
                        $params = [];

                        if ($owner instanceof \App\Domain\PurchaseOrders\Models\PurchaseOrder) {
                            $params['direction'] = 'outbound';
                            $params['company_id'] = $owner->supplier_company_id;
                        } elseif ($owner instanceof \App\Domain\ProformaInvoices\Models\ProformaInvoice) {
                            $params['direction'] = 'inbound';
                            $params['company_id'] = $owner->company_id;
                        }

                        return PaymentResource::getUrl('create', $params);
                    })
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('viewPayments')
                    ->label(__('forms.labels.view_deposits'))
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
            return '<p style="font-size: 14px; color: #9ca3af; padding: 16px 0;">No deposits recorded for this schedule item.</p>';
        }

        $thStyle = 'text-align: left; padding: 12px 16px; font-weight: 700; font-size: 13px; color: #6b7280; border-bottom: 2px solid #e5e7eb;';
        $thRightStyle = 'text-align: right; padding: 12px 16px; font-weight: 700; font-size: 13px; color: #6b7280; border-bottom: 2px solid #e5e7eb;';
        $tdStyle = 'padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f3f4f6;';
        $tdRightStyle = 'padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600;';

        $html = '<div style="min-width: 620px; padding: 8px 0;">';

        if ($regularAllocations->isNotEmpty()) {
            $html .= '<div style="margin-bottom: 24px;">';
            $html .= '<div style="font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">Payments</div>';
            $html .= '<table style="width: 100%; font-size: 14px; border-collapse: collapse; table-layout: fixed;">';
            $html .= '<colgroup>';
            $html .= '<col style="width: 14%;">';
            $html .= '<col style="width: 17%;">';
            $html .= '<col style="width: 9%;">';
            $html .= '<col style="width: 17%;">';
            $html .= '<col style="width: 17%;">';
            $html .= '<col style="width: 14%;">';
            $html .= '<col style="width: 12%;">';
            $html .= '</colgroup>';
            $html .= '<thead><tr>';
            $html .= '<th style="' . $thStyle . '">Date</th>';
            $html .= '<th style="' . $thRightStyle . '">Amount</th>';
            $html .= '<th style="' . $thStyle . '">Cur.</th>';
            $html .= '<th style="' . $thStyle . '">Method</th>';
            $html .= '<th style="' . $thStyle . '">Reference</th>';
            $html .= '<th style="' . $thStyle . '">Status</th>';
            $html .= '<th style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e5e7eb;"></th>';
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
                    PaymentStatus::APPROVED => '#16a34a',
                    PaymentStatus::PENDING_APPROVAL => '#ca8a04',
                    PaymentStatus::REJECTED => '#dc2626',
                    default => '#9ca3af',
                };
                $statusLabel = $payment->status instanceof BackedEnum ? ucfirst($payment->status->value) : $payment->status;
                $viewUrl = PaymentResource::getUrl('view', ['record' => $payment]);

                $html .= '<tr>';
                $html .= '<td style="' . $tdStyle . '">' . $date . '</td>';
                $html .= '<td style="' . $tdRightStyle . '">' . $amount . '</td>';
                $html .= '<td style="' . $tdStyle . '">' . $currency . '</td>';
                $html .= '<td style="' . $tdStyle . '">' . $method . '</td>';
                $html .= '<td style="' . $tdStyle . '">' . $ref . '</td>';
                $html .= '<td style="' . $tdStyle . ' font-weight: 600; color: ' . $statusColor . ';">' . $statusLabel . '</td>';
                $html .= '<td style="padding: 12px 16px; border-bottom: 1px solid #f3f4f6; text-align: center;"><a href="' . $viewUrl . '" target="_blank" style="color: #7c3aed; text-decoration: none; font-weight: 600; font-size: 13px;">View</a></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '<tfoot><tr>';
            $html .= '<td style="padding: 12px 16px; font-weight: 700; text-align: right; border-top: 2px solid #d1d5db;">Subtotal:</td>';
            $html .= '<td style="padding: 12px 16px; text-align: right; font-weight: 700; border-top: 2px solid #d1d5db;">' . Money::format($totalPayments) . '</td>';
            $html .= '<td colspan="5" style="border-top: 2px solid #d1d5db;"></td>';
            $html .= '</tr></tfoot></table></div>';
        }

        if ($creditAllocations->isNotEmpty()) {
            $html .= '<div style="margin-bottom: 24px;">';
            $html .= '<div style="font-size: 12px; font-weight: 700; color: #16a34a; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">Credits Applied</div>';
            $html .= '<table style="width: 100%; font-size: 14px; border-collapse: collapse; table-layout: fixed;">';
            $html .= '<colgroup>';
            $html .= '<col style="width: 55%;">';
            $html .= '<col style="width: 30%;">';
            $html .= '<col style="width: 15%;">';
            $html .= '</colgroup>';
            $html .= '<thead><tr>';
            $html .= '<th style="' . $thStyle . '">Credit Source</th>';
            $html .= '<th style="' . $thRightStyle . '">Amount</th>';
            $html .= '<th style="' . $thStyle . '">Cur.</th>';
            $html .= '</tr></thead><tbody>';

            $totalCredits = 0;

            foreach ($creditAllocations as $alloc) {
                $creditLabel = e($alloc->creditItem?->label ?? 'Credit');
                $creditAmount = $alloc->allocated_amount_in_document_currency;
                $totalCredits += $creditAmount;

                $html .= '<tr>';
                $html .= '<td style="' . $tdStyle . ' color: #16a34a; font-weight: 600;">' . $creditLabel . '</td>';
                $html .= '<td style="' . $tdRightStyle . ' color: #16a34a;">' . Money::format($creditAmount) . '</td>';
                $html .= '<td style="' . $tdStyle . '">' . e($item->currency_code) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '<tfoot><tr>';
            $html .= '<td style="padding: 12px 16px; font-weight: 700; text-align: right; border-top: 2px solid #d1d5db;">Subtotal:</td>';
            $html .= '<td style="padding: 12px 16px; text-align: right; font-weight: 700; color: #16a34a; border-top: 2px solid #d1d5db;">' . Money::format($totalCredits) . '</td>';
            $html .= '<td style="border-top: 2px solid #d1d5db;"></td>';
            $html .= '</tr></tfoot></table></div>';
        }

        $html .= '</div>';

        $remaining = $item->remaining_amount;
        $totalPaid = $regularAllocations->sum('allocated_amount');
        $totalCreditApplied = $creditAllocations->sum('allocated_amount_in_document_currency');

        $bgColor = $remaining > 0 ? '#fefce8' : '#f0fdf4';
        $borderColor = $remaining > 0 ? '#fde68a' : '#bbf7d0';

        $html .= '<div style="margin-top: 16px; padding: 16px; border-radius: 8px; border: 1px solid ' . $borderColor . '; background: ' . $bgColor . ';">';
        $html .= '<div style="display: flex; align-items: center; gap: 40px; font-size: 14px; font-weight: 700;">';
        $html .= '<span><span style="color: #6b7280;">Due:</span> <span style="color: #111827;">' . $item->currency_code . ' ' . Money::format($item->amount) . '</span></span>';
        $html .= '<span><span style="color: #6b7280;">Paid:</span> <span style="color: #2563eb;">' . $item->currency_code . ' ' . Money::format($totalPaid) . '</span></span>';
        if ($totalCreditApplied > 0) {
            $html .= '<span><span style="color: #6b7280;">Credits:</span> <span style="color: #16a34a;">' . $item->currency_code . ' ' . Money::format($totalCreditApplied) . '</span></span>';
        }
        if ($remaining > 0) {
            $html .= '<span><span style="color: #dc2626;">Remaining: ' . $item->currency_code . ' ' . Money::format($remaining) . '</span></span>';
        } else {
            $html .= '<span><span style="color: #16a34a;">Fully Paid</span></span>';
        }
        $html .= '</div></div>';

        return $html;
    }
}
