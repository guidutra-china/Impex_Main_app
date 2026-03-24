<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
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
            $data = $this->buildViewData();

            \Illuminate\Support\Facades\Log::info('UPCOMING WIDGET', [
                'tenant_id' => Filament::getTenant()?->id,
                'tenant_name' => Filament::getTenant()?->name,
                'hasAny' => $data['hasAny'],
                'overdueCount' => $data['overdueCount'],
                'pendingCount' => $data['pendingCount'],
                'weekCount' => $data['weekCount'],
            ]);

            return $data;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UPCOMING WIDGET ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
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

        $baseQuery = PaymentScheduleItem::query()
            ->with('payable')
            ->where('is_credit', false)
            ->whereIn('status', [
                PaymentScheduleStatus::PENDING,
                PaymentScheduleStatus::DUE,
                PaymentScheduleStatus::OVERDUE,
            ])
            ->where(function ($query) use ($tenant) {
                $query->whereHasMorph('payable', [ProformaInvoice::class], function ($q) use ($tenant) {
                    $q->where('company_id', $tenant->id);
                })->orWhereHasMorph('payable', [Shipment::class], function ($q) use ($tenant) {
                    $q->where('company_id', $tenant->id);
                });
            });

        // Overdue: explicit OVERDUE status OR past due_date with PENDING/DUE status
        $overdueItems = (clone $baseQuery)
            ->where(function ($query) use ($today) {
                $query->where('status', PaymentScheduleStatus::OVERDUE)
                    ->orWhere(function ($q) use ($today) {
                        $q->whereNotNull('due_date')
                            ->where('due_date', '<', $today)
                            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE]);
                    });
            })
            ->orderBy('due_date')
            ->get();

        // Due this week (today to +7 days, not overdue)
        $weekItems = (clone $baseQuery)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $endOfWeek])
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->orderBy('due_date')
            ->get();

        // Due this month (+8 to +30 days)
        $monthItems = (clone $baseQuery)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$endOfWeek->copy()->addDay(), $endOfMonth])
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->orderBy('due_date')
            ->get();

        // Pending without due_date (show separately so they're not hidden)
        $pendingNoDueDate = (clone $baseQuery)
            ->whereNull('due_date')
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->orderBy('created_at')
            ->get();

        $mapItem = function ($item) use ($today) {
            $payable = $item->payable;
            $docType = match (true) {
                $payable instanceof ProformaInvoice => 'PI',
                $payable instanceof Shipment => 'Shipment',
                default => 'Doc',
            };
            $docColor = match ($docType) {
                'PI' => 'primary',
                'Shipment' => 'info',
                default => 'gray',
            };
            $ref = ($payable instanceof Shipment && $payable->bl_number)
                ? $payable->bl_number
                : ($payable?->reference ?? '—');

            return [
                'doc_type' => $docType,
                'doc_color' => $docColor,
                'reference' => $ref,
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
        $pendingTotal = $pendingNoDueDate->sum('remaining_amount');
        $currency = $overdueItems->first()?->currency_code
            ?? $weekItems->first()?->currency_code
            ?? $monthItems->first()?->currency_code
            ?? $pendingNoDueDate->first()?->currency_code
            ?? 'USD';

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
            'pending' => $pendingNoDueDate->map($mapItem)->all(),
            'pendingTotal' => Money::format($pendingTotal, 2),
            'pendingCount' => $pendingNoDueDate->count(),
            'currency' => $currency,
            'hasAny' => $overdueItems->isNotEmpty() || $weekItems->isNotEmpty() || $monthItems->isNotEmpty() || $pendingNoDueDate->isNotEmpty(),
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
            'pending' => [],
            'pendingTotal' => '0.00',
            'pendingCount' => 0,
            'currency' => 'USD',
            'hasAny' => false,
        ];
    }
}
