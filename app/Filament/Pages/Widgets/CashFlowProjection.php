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

            $inflowConverted = $this->convertToBase($inflowByCurrency, $baseCurrencyId);
            $outflowConverted = $this->convertToBase($outflowByCurrency, $baseCurrencyId);
            $net = $inflowConverted - $outflowConverted;

            $runningInflow += $inflowConverted;
            $runningOutflow += $outflowConverted;

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
                'inflow' => Money::format($inflowConverted),
                'inflow_raw' => $inflowConverted,
                'outflow' => Money::format($outflowConverted),
                'outflow_raw' => $outflowConverted,
                'net' => Money::format(abs($net)),
                'net_raw' => $net,
                'inflow_count' => $periodItems->where('payable_type', $piMorphClass)->count(),
                'outflow_count' => $periodItems->where('payable_type', $poMorphClass)->count(),
                'overdue_inflow' => $overdueInflow,
                'overdue_outflow' => $overdueOutflow,
            ];
        }

        $noDateItems = $pendingItems->whereNull('due_date');
        $noDateInflow = $this->convertToBase(
            $noDateItems->where('payable_type', $piMorphClass)->groupBy('currency_code')->map(fn ($items) => $items->sum('amount')),
            $baseCurrencyId
        );
        $noDateOutflow = $this->convertToBase(
            $noDateItems->where('payable_type', $poMorphClass)->groupBy('currency_code')->map(fn ($items) => $items->sum('amount')),
            $baseCurrencyId
        );

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
                'inflow' => Money::format($noDateInflow),
                'inflow_raw' => $noDateInflow,
                'outflow' => Money::format($noDateOutflow),
                'outflow_raw' => $noDateOutflow,
            ],
        ];
    }

    private function buildPeriods(): array
    {
        $today = now()->startOfDay();

        return [
            [
                'label' => 'Overdue',
                'range' => 'Before today',
                'start' => Carbon::create(2000, 1, 1),
                'end' => $today->copy()->subDay()->endOfDay(),
            ],
            [
                'label' => 'This Week',
                'range' => $today->format('d/m') . ' – ' . $today->copy()->endOfWeek()->format('d/m'),
                'start' => $today->copy(),
                'end' => $today->copy()->endOfWeek()->endOfDay(),
            ],
            [
                'label' => 'Next Week',
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

    private function convertToBase($amountsByCurrency, ?int $baseCurrencyId): int
    {
        if (! $baseCurrencyId || $amountsByCurrency->isEmpty()) {
            return (int) $amountsByCurrency->sum();
        }

        $total = 0;

        foreach ($amountsByCurrency as $currencyCode => $amount) {
            $currency = Currency::findByCode($currencyCode);

            if (! $currency || $currency->id === $baseCurrencyId) {
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
