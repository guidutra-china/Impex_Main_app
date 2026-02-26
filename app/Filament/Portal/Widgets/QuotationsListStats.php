<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class QuotationsListStats extends Widget
{
    protected string $view = 'portal.widgets.quotations-list-stats';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $query = Quotation::where('company_id', $tenant->id);

        $total = $query->count();
        $pending = (clone $query)->whereIn('status', [
            QuotationStatus::SENT,
            QuotationStatus::NEGOTIATING,
        ])->count();
        $approved = (clone $query)->where('status', QuotationStatus::APPROVED)->count();
        $rejected = (clone $query)->where('status', QuotationStatus::REJECTED)->count();

        $showFinancial = auth()->user()?->can('portal:view-financial-summary');

        $totalValue = null;
        $approvedValue = null;
        $currency = null;

        if ($showFinancial) {
            $allQuotations = (clone $query)->with('items')->get();
            $approvedQuotations = $allQuotations->where('status', QuotationStatus::APPROVED);

            $totalValue = $allQuotations->sum(fn ($q) => $q->total);
            $approvedValue = $approvedQuotations->sum(fn ($q) => $q->total);
            $currency = $allQuotations->first()?->currency_code ?? 'USD';
        }

        $statusBreakdown = [];
        foreach (QuotationStatus::cases() as $status) {
            $count = (clone $query)->where('status', $status)->count();
            if ($count > 0) {
                $statusBreakdown[] = [
                    'label' => $status->getEnglishLabel(),
                    'count' => $count,
                    'color' => $status->getColor(),
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                ];
            }
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'showFinancial' => $showFinancial,
            'totalValue' => $totalValue !== null ? Money::format($totalValue) : null,
            'approvedValue' => $approvedValue !== null ? Money::format($approvedValue) : null,
            'currency' => $currency,
            'statusBreakdown' => $statusBreakdown,
        ];
    }
}
