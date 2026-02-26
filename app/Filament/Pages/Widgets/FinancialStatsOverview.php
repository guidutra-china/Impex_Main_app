<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Finance\Models\CompanyExpense;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class FinancialStatsOverview extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.financial-stats-overview';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    protected function getViewData(): array
    {
        $baseCurrency = Currency::base();
        $baseCurrencyCode = $baseCurrency?->code ?? 'USD';
        $baseCurrencyId = $baseCurrency?->id;

        $receivables = $this->buildReceivables($baseCurrencyId, $baseCurrencyCode);
        $payables = $this->buildPayables($baseCurrencyId, $baseCurrencyCode);
        $alerts = $this->buildAlerts();
        $cashflow = $this->buildCashflow($receivables, $payables);
        $operationalExpenses = $this->buildOperationalExpenses($baseCurrencyId);

        return [
            'baseCurrencyCode' => $baseCurrencyCode,
            'receivables' => $receivables,
            'payables' => $payables,
            'alerts' => $alerts,
            'cashflow' => $cashflow,
            'operationalExpenses' => $operationalExpenses,
        ];
    }

    private function buildReceivables(?int $baseCurrencyId, string $baseCurrencyCode): array
    {
        $piMorphClass = (new ProformaInvoice)->getMorphClass();

        $outstandingItems = PaymentScheduleItem::query()
            ->where('payable_type', $piMorphClass)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $overdueItems = PaymentScheduleItem::query()
            ->where('payable_type', $piMorphClass)
            ->where('is_credit', false)
            ->where('status', PaymentScheduleStatus::OVERDUE->value)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $receivedByCurrency = Payment::inbound()
            ->approved()
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $outstandingConverted = $this->convertToBase($outstandingItems, $baseCurrencyId);
        $overdueConverted = $this->convertToBase($overdueItems, $baseCurrencyId);
        $receivedConverted = $this->convertToBase($receivedByCurrency, $baseCurrencyId);

        $openPIs = ProformaInvoice::whereHas('paymentScheduleItems', function ($q) {
            $q->where('is_credit', false)
                ->whereNotIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ]);
        })->count();

        return [
            'outstanding' => Money::format($outstandingConverted),
            'outstanding_raw' => $outstandingConverted,
            'overdue' => Money::format($overdueConverted),
            'overdue_raw' => $overdueConverted,
            'received' => Money::format($receivedConverted),
            'received_raw' => $receivedConverted,
            'open_documents' => $openPIs,
            'by_currency' => $outstandingItems->map(fn ($amount, $code) => [
                'code' => $code,
                'amount' => Money::format((int) $amount),
            ])->values()->all(),
        ];
    }

    private function buildPayables(?int $baseCurrencyId, string $baseCurrencyCode): array
    {
        $poMorphClass = (new PurchaseOrder)->getMorphClass();

        $outstandingItems = PaymentScheduleItem::query()
            ->where('payable_type', $poMorphClass)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $overdueItems = PaymentScheduleItem::query()
            ->where('payable_type', $poMorphClass)
            ->where('is_credit', false)
            ->where('status', PaymentScheduleStatus::OVERDUE->value)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $paidByCurrency = Payment::outbound()
            ->approved()
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $outstandingConverted = $this->convertToBase($outstandingItems, $baseCurrencyId);
        $overdueConverted = $this->convertToBase($overdueItems, $baseCurrencyId);
        $paidConverted = $this->convertToBase($paidByCurrency, $baseCurrencyId);

        $openPOs = PurchaseOrder::whereHas('paymentScheduleItems', function ($q) {
            $q->where('is_credit', false)
                ->whereNotIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ]);
        })->count();

        return [
            'outstanding' => Money::format($outstandingConverted),
            'outstanding_raw' => $outstandingConverted,
            'overdue' => Money::format($overdueConverted),
            'overdue_raw' => $overdueConverted,
            'paid' => Money::format($paidConverted),
            'paid_raw' => $paidConverted,
            'open_documents' => $openPOs,
            'by_currency' => $outstandingItems->map(fn ($amount, $code) => [
                'code' => $code,
                'amount' => Money::format((int) $amount),
            ])->values()->all(),
        ];
    }

    private function buildAlerts(): array
    {
        $alerts = [];

        $pendingApproval = Payment::query()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->count();

        if ($pendingApproval > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-clock',
                'text' => $pendingApproval . ' payment' . ($pendingApproval > 1 ? 's' : '') . ' pending approval',
            ];
        }

        $overdueCount = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->where('status', PaymentScheduleStatus::OVERDUE->value)
            ->count();

        if ($overdueCount > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'text' => $overdueCount . ' overdue payment' . ($overdueCount > 1 ? 's' : '') . ' across all documents',
            ];
        }

        $dueThisWeek = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->where('status', PaymentScheduleStatus::DUE->value)
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        if ($dueThisWeek > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'heroicon-o-calendar',
                'text' => $dueThisWeek . ' payment' . ($dueThisWeek > 1 ? 's' : '') . ' due this week',
            ];
        }

        return $alerts;
    }

    private function buildCashflow(array $receivables, array $payables): array
    {
        $netPosition = $receivables['received_raw'] - $payables['paid_raw'];
        $netOutstanding = $receivables['outstanding_raw'] - $payables['outstanding_raw'];

        return [
            'net_position' => Money::format(abs($netPosition)),
            'net_position_raw' => $netPosition,
            'net_position_label' => $netPosition >= 0 ? __('widgets.financial_stats.net_positive') : __('widgets.financial_stats.net_negative'),
            'net_outstanding' => Money::format(abs($netOutstanding)),
            'net_outstanding_raw' => $netOutstanding,
            'net_outstanding_label' => $netOutstanding >= 0 ? __('widgets.financial_stats.net_to_receive') : __('widgets.financial_stats.net_to_pay'),
        ];
    }

    private function buildOperationalExpenses(?int $baseCurrencyId): array
    {
        $now = Carbon::now();

        $currentMonthExpenses = CompanyExpense::query()
            ->inMonth($now->year, $now->month)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->get();

        $currentTotal = 0;
        foreach ($currentMonthExpenses as $row) {
            $currentTotal += $this->convertSingleToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
        }

        $previousMonth = $now->copy()->subMonth();
        $previousMonthExpenses = CompanyExpense::query()
            ->inMonth($previousMonth->year, $previousMonth->month)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->get();

        $previousTotal = 0;
        foreach ($previousMonthExpenses as $row) {
            $previousTotal += $this->convertSingleToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
        }

        $change = $previousTotal > 0
            ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
            : ($currentTotal > 0 ? 100 : 0);

        return [
            'current_month' => Money::format($currentTotal),
            'current_month_raw' => $currentTotal,
            'previous_month' => Money::format($previousTotal),
            'previous_month_raw' => $previousTotal,
            'change' => $change,
            'month_label' => $now->translatedFormat('F'),
        ];
    }

    private function convertSingleToBase(int $amountMinor, string $currencyCode, ?int $baseCurrencyId): int
    {
        if (! $baseCurrencyId) {
            return $amountMinor;
        }

        $currency = Currency::findByCode($currencyCode);
        if (! $currency || $currency->id === $baseCurrencyId) {
            return $amountMinor;
        }

        $converted = ExchangeRate::convert(
            $currency->id,
            $baseCurrencyId,
            Money::toMajor($amountMinor),
        );

        return $converted !== null ? Money::toMinor($converted) : $amountMinor;
    }

    private function convertToBase($amountsByCurrency, ?int $baseCurrencyId): int
    {
        if (! $baseCurrencyId) {
            return (int) $amountsByCurrency->sum();
        }

        $total = 0;

        foreach ($amountsByCurrency as $currencyCode => $amount) {
            $currency = Currency::findByCode($currencyCode);

            if (! $currency) {
                $total += (int) $amount;
                continue;
            }

            if ($currency->id === $baseCurrencyId) {
                $total += (int) $amount;
                continue;
            }

            $converted = ExchangeRate::convert(
                $currency->id,
                $baseCurrencyId,
                Money::toMajor((int) $amount),
            );

            $total += $converted !== null
                ? Money::toMinor($converted)
                : (int) $amount;
        }

        return $total;
    }
}
