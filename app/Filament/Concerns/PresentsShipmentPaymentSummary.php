<?php

namespace App\Filament\Concerns;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Enums\CalculationBase;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns ShipmentPaymentSummaryService sections into display-ready arrays for
 * the shared filament.widgets.shipment-payment-progress view.
 */
trait PresentsShipmentPaymentSummary
{
    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array{title: string, sections: array<int, array<string, mixed>>}|null
     */
    protected function buildPaymentSummaryBlock(string $title, array $sections): ?array
    {
        $sections = collect($sections)
            ->filter(fn (array $section) => $section['stages'] !== [])
            ->map(fn (array $section) => [
                'currency' => $section['currency'],
                'stages' => array_map(fn (array $stage) => $this->presentStage($stage), $section['stages']),
                'totals' => [
                    'amount' => Money::formatDisplay($section['totals']['amount']),
                    'paid' => Money::formatDisplay($section['totals']['paid']),
                    'remaining' => Money::formatDisplay($section['totals']['remaining']),
                    'percent_paid' => $section['totals']['percent_paid'],
                ],
            ])
            ->values()
            ->all();

        if ($sections === []) {
            return null;
        }

        return ['title' => $title, 'sections' => $sections];
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private function presentStage(array $stage): array
    {
        $conditionLabel = $stage['condition']
            ? CalculationBase::tryFrom($stage['condition'])?->getLabel() ?? $stage['condition']
            : __('messages.shipment_payments_no_condition');

        $label = $stage['nominal_percentage'] !== null
            ? "{$stage['nominal_percentage']}% — {$conditionLabel}"
            : "{$conditionLabel} — {$stage['effective_percentage']}%";

        $dueDate = $stage['next_due_date'] ?? null;

        if ($dueDate !== null && ! $dueDate instanceof CarbonInterface) {
            $dueDate = Carbon::parse($dueDate);
        }

        return [
            'label' => $label,
            'prorated' => $stage['prorated'],
            'status' => $stage['status'],
            'icon' => match ($stage['status']) {
                'paid' => 'heroicon-o-check-circle',
                'partial' => 'heroicon-o-clock',
                'overdue' => 'heroicon-o-exclamation-circle',
                default => 'heroicon-o-clock',
            },
            'color' => match ($stage['status']) {
                'paid' => 'success',
                'partial' => 'warning',
                'overdue' => 'danger',
                default => 'gray',
            },
            'amount' => Money::formatDisplay($stage['amount']),
            'paid' => Money::formatDisplay($stage['paid']),
            'remaining' => Money::formatDisplay($stage['remaining']),
            'has_difference' => $stage['remaining'] > 100,
            'due_date' => $dueDate?->format('d/m/Y'),
        ];
    }
}
