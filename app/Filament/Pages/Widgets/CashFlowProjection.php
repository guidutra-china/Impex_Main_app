<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class CashFlowProjection extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.cash-flow-projection';

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

        $piMorphClass = (new ProformaInvoice)->getMorphClass();
        $poMorphClass = (new PurchaseOrder)->getMorphClass();

        $periods = $this->buildPeriods();

        $pendingItems = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereNotNull('due_date')
            ->select(['payable_type', 'currency_code', 'amount', 'due_date', 'status'])
            ->get();

        $projection = [];
        $runningInflow = 0;
        $runningOutflow = 0;
        $hasConversionWarning = false;
        $allUnconverted = [];

        foreach ($periods as $period) {
            $periodItems = $pendingItems->filter(function ($item) use ($period) {
                $dueDate = Carbon::parse($item->due_date);

                return $dueDate->gte($period['start']) && $dueDate->lte($period['end']);
            });

            $inflowByCurrency = $periodItems
                ->where('payable_type', $piMorphClass)
                ->groupBy('currency_code')
                ->map(fn ($items) => $items->sum('amount'));

            $outflowByCurrency = $periodItems
                ->where('payable_type', $poMorphClass)
                ->groupBy('currency_code')
                ->map(fn ($items) => $items->sum('amount'));

            $inflowResult = $this->convertToBase($inflowByCurrency, $baseCurrencyId);
            $outflowResult = $this->convertToBase($outflowByCurrency, $baseCurrencyId);
            $net = $inflowResult['total'] - $outflowResult['total'];

            $runningInflow += $inflowResult['total'];
            $runningOutflow += $outflowResult['total'];

            if ($inflowResult['has_warning'] || $outflowResult['has_warning']) {
                $hasConversionWarning = true;
                foreach (array_merge($inflowResult['unconverted_codes'], $outflowResult['unconverted_codes']) as $code) {
                    $allUnconverted[$code] = true;
                }
            }

            $overdueInflow = $periodItems
                ->where('payable_type', $piMorphClass)
                ->where('status', PaymentScheduleStatus::OVERDUE->value)
                ->count();

            $overdueOutflow = $periodItems
                ->where('payable_type', $poMorphClass)
                ->where('status', PaymentScheduleStatus::OVERDUE->value)
                ->count();

            $projection[] = [
                'label' => $period['label'],
                'range' => $period['range'],
                'inflow' => Money::format($inflowResult['total']),
                'inflow_raw' => $inflowResult['total'],
                'outflow' => Money::format($outflowResult['total']),
                'outflow_raw' => $outflowResult['total'],
                'net' => Money::format(abs($net)),
                'net_raw' => $net,
                'inflow_count' => $periodItems->where('payable_type', $piMorphClass)->count(),
                'outflow_count' => $periodItems->where('payable_type', $poMorphClass)->count(),
                'overdue_inflow' => $overdueInflow,
                'overdue_outflow' => $overdueOutflow,
            ];
        }

        $noDateItems = $pendingItems->whereNull('due_date');
        $noDateInflowResult = $this->convertToBase(
            $noDateItems->where('payable_type', $piMorphClass)->groupBy('currency_code')->map(fn ($items) => $items->sum('amount')),
            $baseCurrencyId
        );
        $noDateOutflowResult = $this->convertToBase(
            $noDateItems->where('payable_type', $poMorphClass)->groupBy('currency_code')->map(fn ($items) => $items->sum('amount')),
            $baseCurrencyId
        );

        if ($noDateInflowResult['has_warning'] || $noDateOutflowResult['has_warning']) {
            $hasConversionWarning = true;
            foreach (array_merge($noDateInflowResult['unconverted_codes'], $noDateOutflowResult['unconverted_codes']) as $code) {
                $allUnconverted[$code] = true;
            }
        }

        return [
            'baseCurrencyCode' => $baseCurrencyCode,
            'projection' => $projection,
            'totals' => [
                'inflow' => Money::format($runningInflow),
                'inflow_raw' => $runningInflow,
                'outflow' => Money::format($runningOutflow),
                'outflow_raw' => $runningOutflow,
                'net' => Money::format(abs($runningInflow - $runningOutflow)),
                'net_raw' => $runningInflow - $runningOutflow,
            ],
            'unscheduled' => [
                'inflow' => Money::format($noDateInflowResult['total']),
                'inflow_raw' => $noDateInflowResult['total'],
                'outflow' => Money::format($noDateOutflowResult['total']),
                'outflow_raw' => $noDateOutflowResult['total'],
            ],
            'has_conversion_warning' => $hasConversionWarning,
            'unconverted_currencies' => array_keys($allUnconverted),
        ];
    }

    private function buildPeriods(): array
    {
        $today = now()->startOfDay();

        return [
            [
                'label' => __('widgets.cash_flow.overdue'),
                'range' => __('widgets.cash_flow.before_today'),
                'start' => Carbon::create(2000, 1, 1),
                'end' => $today->copy()->subDay()->endOfDay(),
            ],
            [
                'label' => __('widgets.cash_flow.this_week'),
                'range' => $today->format('d/m') . ' – ' . $today->copy()->endOfWeek()->format('d/m'),
                'start' => $today->copy(),
                'end' => $today->copy()->endOfWeek()->endOfDay(),
            ],
            [
                'label' => __('widgets.cash_flow.next_week'),
                'range' => $today->copy()->addWeek()->startOfWeek()->format('d/m') . ' – ' . $today->copy()->addWeek()->endOfWeek()->format('d/m'),
                'start' => $today->copy()->addWeek()->startOfWeek(),
                'end' => $today->copy()->addWeek()->endOfWeek()->endOfDay(),
            ],
            [
                'label' => '15 Days',
                'range' => $today->copy()->addWeeks(2)->startOfWeek()->format('d/m') . ' – ' . $today->copy()->addDays(15)->format('d/m'),
                'start' => $today->copy()->addWeeks(2)->startOfWeek(),
                'end' => $today->copy()->addDays(15)->endOfDay(),
            ],
            [
                'label' => '30 Days',
                'range' => $today->copy()->addDays(16)->format('d/m') . ' – ' . $today->copy()->addDays(30)->format('d/m'),
                'start' => $today->copy()->addDays(16),
                'end' => $today->copy()->addDays(30)->endOfDay(),
            ],
            [
                'label' => '60 Days',
                'range' => $today->copy()->addDays(31)->format('d/m') . ' – ' . $today->copy()->addDays(60)->format('d/m'),
                'start' => $today->copy()->addDays(31),
                'end' => $today->copy()->addDays(60)->endOfDay(),
            ],
            [
                'label' => '90 Days',
                'range' => $today->copy()->addDays(61)->format('d/m') . ' – ' . $today->copy()->addDays(90)->format('d/m'),
                'start' => $today->copy()->addDays(61),
                'end' => $today->copy()->addDays(90)->endOfDay(),
            ],
        ];
    }

    private function convertToBase($amountsByCurrency, ?int $baseCurrencyId): array
    {
        if (! $baseCurrencyId || $amountsByCurrency->isEmpty()) {
            return [
                'total' => (int) $amountsByCurrency->sum(),
                'has_warning' => false,
                'unconverted_codes' => [],
            ];
        }

        $total = 0;
        $hasWarning = false;
        $unconvertedCodes = [];

        foreach ($amountsByCurrency as $currencyCode => $amount) {
            $currency = Currency::findByCode($currencyCode);

            if (! $currency) {
                $hasWarning = true;
                $unconvertedCodes[] = $currencyCode;

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

            if ($converted !== null) {
                $total += Money::toMinor($converted);
            } else {
                $hasWarning = true;
                $unconvertedCodes[] = $currencyCode;
            }
        }

        return [
            'total' => $total,
            'has_warning' => $hasWarning,
            'unconverted_codes' => $unconvertedCodes,
        ];
    }
}
