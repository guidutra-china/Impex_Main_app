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
        $currentMonth = $this->buildMonthData($now->year, $now->month, $baseCurrencyId);
        $previousMonth = $this->buildMonthData(
            $now->copy()->subMonth()->year,
            $now->copy()->subMonth()->month,
            $baseCurrencyId
        );

        $monthOverMonth = $this->calculateMonthOverMonth($currentMonth['total_raw'], $previousMonth['total_raw']);

        $yearToDate = $this->buildYearToDate($now->year, $baseCurrencyId);

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

    private function buildMonthData(int $year, int $month, ?int $baseCurrencyId): array
    {
        $expenses = CompanyExpense::query()
            ->inMonth($year, $month)
            ->selectRaw('category, currency_code, SUM(amount) as total')
            ->groupBy('category', 'currency_code')
            ->get();

        $byCategory = [];
        $totalConverted = 0;

        foreach ($expenses as $row) {
            $converted = $this->convertToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
            $category = $row->category instanceof ExpenseCategory ? $row->category->value : $row->category;

            if (! isset($byCategory[$category])) {
                $byCategory[$category] = 0;
            }
            $byCategory[$category] += $converted;
            $totalConverted += $converted;
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
                'percentage' => $totalConverted > 0 ? round(($amount / $totalConverted) * 100, 1) : 0,
            ];
        }

        usort($categoryBreakdown, fn ($a, $b) => $b['amount_raw'] <=> $a['amount_raw']);

        $expenseCount = CompanyExpense::query()->inMonth($year, $month)->count();

        return [
            'total' => Money::format($totalConverted),
            'total_raw' => $totalConverted,
            'count' => $expenseCount,
            'categories' => $categoryBreakdown,
            'top_category' => $categoryBreakdown[0] ?? null,
        ];
    }

    private function buildYearToDate(int $year, ?int $baseCurrencyId): array
    {
        $expenses = CompanyExpense::query()
            ->inYear($year)
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->get();

        $totalConverted = 0;
        foreach ($expenses as $row) {
            $totalConverted += $this->convertToBase((int) $row->total, $row->currency_code, $baseCurrencyId);
        }

        $monthlyAvg = Carbon::now()->month > 0
            ? (int) round($totalConverted / Carbon::now()->month)
            : 0;

        return [
            'total' => Money::format($totalConverted),
            'total_raw' => $totalConverted,
            'monthly_avg' => Money::format($monthlyAvg),
            'monthly_avg_raw' => $monthlyAvg,
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

    private function convertToBase(int $amountMinor, string $currencyCode, ?int $baseCurrencyId): int
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
}
