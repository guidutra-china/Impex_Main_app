<?php

namespace App\Filament\Portal\Resources\QuotationResource\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Models\Quotation;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalQuotationSummary extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'portal.widgets.quotation-summary';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Quotation) {
            return ['cards' => [], 'items' => []];
        }

        $quotation = $this->record;
        $quotation->loadMissing(['items.product']);

        $currency = $quotation->currency_code ?? 'USD';
        $total = $quotation->total ?? 0;
        $itemCount = $quotation->items->count();

        $cards = [
            [
                'label' => __('widgets.portal.status'),
                'value' => $quotation->status?->getLabel() ?? '—',
                'icon' => $quotation->status?->getIcon() ?? 'heroicon-o-document',
                'color' => $quotation->status?->getColor() ?? 'gray',
            ],
            [
                'label' => __('widgets.portal.currency'),
                'value' => $currency,
                'icon' => 'heroicon-o-currency-dollar',
                'color' => 'info',
            ],
            [
                'label' => __('widgets.portal.items'),
                'value' => (string) $itemCount,
                'icon' => 'heroicon-o-squares-2x2',
                'color' => 'gray',
            ],
        ];

        if (auth()->user()?->can('portal:view-financial-summary')) {
            $cards[] = [
                'label' => __('widgets.portal.total_value'),
                'value' => $currency . ' ' . Money::format($total),
                'icon' => 'heroicon-o-banknotes',
                'color' => 'primary',
            ];
        }

        return [
            'cards' => $cards,
            'currency' => $currency,
        ];
    }
}
