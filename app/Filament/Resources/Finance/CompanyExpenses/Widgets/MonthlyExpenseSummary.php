<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Widgets;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Models\CompanyExpense;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class MonthlyExpenseSummary extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.monthly-expense-summary';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-company-expenses') ?? false;
    }

    protected function getViewData(): array
    {
        $baseCurrency = Currency::base();
        $baseCurrencyCode = $baseCurrency?->code ?? 'USD';
        $baseCurrencyId = $baseCurrency?->id;

        $now = Carbon::now();
        $currentMonth = $this->buildMonthData($now->year, $now->month, $baseCurrencyId, $baseCurrencyCode);
        $previousMonth = $this->buildMonthData(
            $now->copy()->subMonth()->year,
            $now->copy()->subMonth()->month,
            $baseCurrencyId,
            $baseCurrencyCode,
        );

        $monthOverMonth = $this->calculateMonthOverMonth(
            $currentMonth['converted_total_raw'],
            $previousMonth['converted_total_raw']
        );

        $yearToDate = $this->buildYearToDate($now->year, $baseCurrencyId, $baseCurrencyCode);

        return [
            'baseCurrencyCode' => $baseCurrencyCode,
            'currentMonth' => $currentMonth,
            'previousMonth' => $previousMonth,
            'monthOverMonth' => $monthOverMonth,
            'yearToDate' => $yearToDate,
            'currentMonthLabel' => $now->translatedFormat('F Y'),
            'previousMonthLabel' => $now->copy()->subMonth()->translatedFormat('F Y'),
        ];
    }

    private function buildMonthData(int $year, int $month, ?int $baseCurrencyId, string $baseCurrencyCode): array
    {
        $expenses = CompanyExpense::query()
            ->inMonth($year, $month)
            ->selectRaw('category, currency_code, SUM(amount) as total')
            ->groupBy('category', 'currency_code')
            ->get();

        $byCategory = [];
        $convertedTotal = 0;
        $unconvertedByCurrency = [];
        $hasConversionWarning = false;

        foreach ($expenses as $row) {
            $result = $this->convertToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
            $category = $row->category instanceof ExpenseCategory ? $row->category->value : $row->category;

            if ($result['converted']) {
                if (! isset($byCategory[$category])) {
                    $byCategory[$category] = 0;
                }
                $byCategory[$category] += $result['amount'];
                $convertedTotal += $result['amount'];
            } else {
                $hasConversionWarning = true;
                $code = $row->currency_code;
                if (! isset($unconvertedByCurrency[$code])) {
                    $unconvertedByCurrency[$code] = 0;
                }
                $unconvertedByCurrency[$code] += (int) $row->total;

                if (! isset($byCategory[$category])) {
                    $byCategory[$category] = 0;
                }
            }
        }

        $categoryBreakdown = [];
        foreach ($byCategory as $categoryValue => $amount) {
            $categoryEnum = ExpenseCategory::tryFrom($categoryValue);
            $categoryBreakdown[] = [
                'category' => $categoryEnum,
                'label' => $categoryEnum?->getLabel() ?? $categoryValue,
                'color' => $categoryEnum?->getColor() ?? 'gray',
                'icon' => $categoryEnum?->getIcon() ?? 'heroicon-o-ellipsis-horizontal-circle',
                'amount' => Money::format($amount),
                'amount_raw' => $amount,
                'percentage' => $convertedTotal > 0 ? round(($amount / $convertedTotal) * 100, 1) : 0,
            ];
        }

        usort($categoryBreakdown, fn ($a, $b) => $b['amount_raw'] <=> $a['amount_raw']);

        $unconvertedDisplay = [];
        foreach ($unconvertedByCurrency as $code => $amountMinor) {
            $unconvertedDisplay[] = $code . ' ' . Money::format($amountMinor);
        }

        $expenseCount = CompanyExpense::query()->inMonth($year, $month)->count();

        return [
            'total' => Money::format($convertedTotal),
            'total_raw' => $convertedTotal,
            'converted_total_raw' => $convertedTotal,
            'count' => $expenseCount,
            'categories' => $categoryBreakdown,
            'top_category' => $categoryBreakdown[0] ?? null,
            'has_conversion_warning' => $hasConversionWarning,
            'unconverted' => $unconvertedDisplay,
        ];
    }

    private function buildYearToDate(int $year, ?int $baseCurrencyId, string $baseCurrencyCode): array
    {
        $expenses = CompanyExpense::query()
            ->inYear($year)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->get();

        $convertedTotal = 0;
        $unconvertedByCurrency = [];
        $hasConversionWarning = false;

        foreach ($expenses as $row) {
            $result = $this->convertToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
            if ($result['converted']) {
                $convertedTotal += $result['amount'];
            } else {
                $hasConversionWarning = true;
                $code = $row->currency_code;
                if (! isset($unconvertedByCurrency[$code])) {
                    $unconvertedByCurrency[$code] = 0;
                }
                $unconvertedByCurrency[$code] += (int) $row->total;
            }
        }

        $unconvertedDisplay = [];
        foreach ($unconvertedByCurrency as $code => $amountMinor) {
            $unconvertedDisplay[] = $code . ' ' . Money::format($amountMinor);
        }

        $monthlyAvg = Carbon::now()->month > 0
            ? (int) round($convertedTotal / Carbon::now()->month)
            : 0;

        return [
            'total' => Money::format($convertedTotal),
            'total_raw' => $convertedTotal,
            'monthly_avg' => Money::format($monthlyAvg),
            'monthly_avg_raw' => $monthlyAvg,
            'has_conversion_warning' => $hasConversionWarning,
            'unconverted' => $unconvertedDisplay,
        ];
    }

    private function calculateMonthOverMonth(int $current, int $previous): array
    {
        if ($previous === 0) {
            return [
                'change' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : 'neutral',
                'label' => $current > 0 ? '+100%' : '0%',
            ];
        }

        $change = round((($current - $previous) / $previous) * 100, 1);

        return [
            'change' => abs($change),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
            'label' => ($change >= 0 ? '+' : '') . $change . '%',
        ];
    }

    private function convertToBase(int $amountMinor, string $currencyCode, ?int $baseCurrencyId): array
    {
        if (! $baseCurrencyId) {
            return ['amount' => $amountMinor, 'converted' => true];
        }

        $currency = Currency::findByCode($currencyCode);
        if (! $currency) {
            return ['amount' => $amountMinor, 'converted' => false];
        }

        if ($currency->id === $baseCurrencyId) {
            return ['amount' => $amountMinor, 'converted' => true];
        }

        $converted = ExchangeRate::convert(
            $currency->id,
            $baseCurrencyId,
            Money::toMajor($amountMinor),
        );

        if ($converted !== null) {
            return ['amount' => Money::toMinor($converted), 'converted' => true];
        }

        return ['amount' => $amountMinor, 'converted' => false];
    }
}
