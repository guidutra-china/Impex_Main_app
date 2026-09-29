<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class UpcomingPaymentsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'portal.widgets.upcoming-payments';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        try {
            return $this->buildViewData();
        } catch (\Throwable $e) {
            report($e);

            return $this->emptyState();
        }
    }

    private function buildViewData(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return $this->emptyState();
        }

        $today = Carbon::today();
        $endOfWeek = $today->copy()->addDays(7);
        $endOfMonth = $today->copy()->addDays(30);

        // Mesma regra do Contas a Receber do admin: parcelas canônicas da PI,
        // custos cobráveis do cliente e DNs do cliente. Os espelhos de
        // Shipment ficam de fora — a parcela já está na PI, e somar os dois
        // contava a mesma dívida em dobro.
        $open = OpenScheduleItemsQuery::filterByCounterparty(
            OpenScheduleItemsQuery::receivables(),
            $tenant->id,
            PaymentDirection::INBOUND,
        )
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->get();

        // Cada parcela cai em exatamente um card: a soma dos cards é o total em aberto.
        $overdueItems = $open->filter(fn ($item) => $item->status === PaymentScheduleStatus::OVERDUE
            || ($item->due_date && $item->due_date->lt($today)))->values();
        $rest = $open->reject(fn ($item) => $overdueItems->contains($item));

        $weekItems = $rest->filter(fn ($item) => $item->due_date && $item->due_date->lte($endOfWeek))->values();
        $monthItems = $rest->filter(fn ($item) => $item->due_date && $item->due_date->gt($endOfWeek) && $item->due_date->lte($endOfMonth))->values();
        $laterItems = $rest->filter(fn ($item) => $item->due_date && $item->due_date->gt($endOfMonth))->values();
        $pendingNoDueDate = $rest->filter(fn ($item) => ! $item->due_date)->values();

        $mapItem = function ($item) use ($today) {
            $payable = $item->payable;
            $docType = match (true) {
                $payable instanceof ProformaInvoice => 'PI',
                $payable instanceof Shipment => 'Shipment',
                $payable instanceof DebitNote => 'DN',
                default => 'Doc',
            };
            $docColor = match ($docType) {
                'PI' => 'primary',
                'Shipment' => 'info',
                'DN' => 'warning',
                default => 'gray',
            };
            $ref = ($payable instanceof Shipment && $payable->bl_number)
                ? $payable->bl_number
                : ($payable?->reference ?? '—');

            return [
                'doc_type' => $docType,
                'doc_color' => $docColor,
                'reference' => $ref,
                'client_reference' => $payable?->client_reference ?? null,
                'label' => preg_replace('/^\d+%\s*\x{2014}\s*/u', '', preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $item->label ?? '')),
                'percentage' => $item->percentage,
                'amount' => Money::format($item->amount, 2),
                'amount_raw' => $item->amount,
                'remaining' => Money::format($item->remaining_amount, 2),
                'remaining_raw' => $item->remaining_amount,
                'currency' => $item->currency_code ?? 'USD',
                'due_date' => $item->due_date?->format('d/m/Y'),
                'days_until' => $item->due_date ? (int) $today->diffInDays($item->due_date, absolute: false) : null,
                'status_label' => $item->status->getLabel(),
                'status_color' => $item->status->getColor(),
            ];
        };

        $overdueTotal = $overdueItems->sum('remaining_amount');
        $weekTotal = $weekItems->sum('remaining_amount');
        $monthTotal = $monthItems->sum('remaining_amount');
        $laterTotal = $laterItems->sum('remaining_amount');
        $pendingTotal = $pendingNoDueDate->sum('remaining_amount');
        $currency = $open->first()?->currency_code ?? 'USD';

        return [
            'overdue' => $overdueItems->map($mapItem)->all(),
            'overdueTotal' => Money::format($overdueTotal, 2),
            'overdueCount' => $overdueItems->count(),
            'thisWeek' => $weekItems->map($mapItem)->all(),
            'weekTotal' => Money::format($weekTotal, 2),
            'weekCount' => $weekItems->count(),
            'thisMonth' => $monthItems->map($mapItem)->all(),
            'monthTotal' => Money::format($monthTotal, 2),
            'monthCount' => $monthItems->count(),
            'later' => $laterItems->map($mapItem)->all(),
            'laterTotal' => Money::format($laterTotal, 2),
            'laterCount' => $laterItems->count(),
            'pending' => $pendingNoDueDate->map($mapItem)->all(),
            'pendingTotal' => Money::format($pendingTotal, 2),
            'pendingCount' => $pendingNoDueDate->count(),
            'currency' => $currency,
            'hasAny' => $open->isNotEmpty(),
        ];
    }

    private function emptyState(): array
    {
        return [
            'overdue' => [],
            'overdueTotal' => '0.00',
            'overdueCount' => 0,
            'thisWeek' => [],
            'weekTotal' => '0.00',
            'weekCount' => 0,
            'thisMonth' => [],
            'monthTotal' => '0.00',
            'monthCount' => 0,
            'later' => [],
            'laterTotal' => '0.00',
            'laterCount' => 0,
            'pending' => [],
            'pendingTotal' => '0.00',
            'pendingCount' => 0,
            'currency' => 'USD',
            'hasAny' => false,
        ];
    }
}
