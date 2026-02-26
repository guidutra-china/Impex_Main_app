<?php

namespace App\Filament\Resources\CRM\Companies\Widgets;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class CompanyFinancialStatement extends Widget
{
    protected string $view = 'filament.widgets.company-financial-statement';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Company) {
            return ['sections' => []];
        }

        $company = $this->record;
        $company->loadMissing('companyRoles');
        $sections = [];

        if ($company->isClient()) {
            $sections[] = $this->buildClientStatement($company);
        }

        if ($company->isSupplier()) {
            $sections[] = $this->buildSupplierStatement($company);
        }

        return ['sections' => $sections];
    }

    private function buildClientStatement(Company $company): array
    {
        $invoices = ProformaInvoice::where('company_id', $company->id)
            ->whereNotIn('status', ['cancelled'])
            ->with(['paymentScheduleItems'])
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $totalInvoiced = 0;
        $totalPaid = 0;
        $totalOverdue = 0;

        foreach ($invoices as $pi) {
            $currency = $pi->currency_code ?? 'USD';
            $total = $pi->total;
            $scheduleItems = $pi->paymentScheduleItems->where('is_credit', false);
            $paid = $scheduleItems->sum(fn ($i) => $i->paid_amount);
            $remaining = max(0, $total - $paid);
            $overdue = $scheduleItems
                ->where('status', PaymentScheduleStatus::OVERDUE)
                ->sum(fn ($i) => $i->remaining_amount);

            $totalInvoiced += $total;
            $totalPaid += $paid;
            $totalOverdue += $overdue;

            $rows[] = [
                'reference' => $pi->reference,
                'status' => $pi->status,
                'date' => $pi->created_at->format('M d, Y'),
                'currency' => $currency,
                'total' => Money::format($total),
                'paid' => Money::format($paid),
                'remaining' => Money::format($remaining),
                'overdue' => $overdue > 0 ? Money::format($overdue) : null,
                'url' => route('filament.admin.resources.proforma-invoices.view', $pi),
            ];
        }

        $unallocatedPayments = $this->getUnallocatedPayments($company->id, PaymentDirection::INBOUND);

        return [
            'title' => 'Client Statement — Receivables',
            'icon' => 'heroicon-o-arrow-down-left',
            'color' => 'success',
            'rows' => $rows,
            'summary' => [
                'total_invoiced' => Money::format($totalInvoiced),
                'total_paid' => Money::format($totalPaid),
                'total_remaining' => Money::format(max(0, $totalInvoiced - $totalPaid)),
                'total_overdue' => $totalOverdue > 0 ? Money::format($totalOverdue) : null,
            ],
            'unallocated_payments' => $unallocatedPayments,
            'type' => 'client',
        ];
    }

    private function buildSupplierStatement(Company $company): array
    {
        $orders = PurchaseOrder::where('supplier_company_id', $company->id)
            ->whereNotIn('status', ['cancelled'])
            ->with(['paymentScheduleItems'])
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $totalOrdered = 0;
        $totalPaid = 0;
        $totalOverdue = 0;

        foreach ($orders as $po) {
            $currency = $po->currency_code ?? 'USD';
            $total = $po->total;
            $scheduleItems = $po->paymentScheduleItems->where('is_credit', false);
            $paid = $scheduleItems->sum(fn ($i) => $i->paid_amount);
            $remaining = max(0, $total - $paid);
            $overdue = $scheduleItems
                ->where('status', PaymentScheduleStatus::OVERDUE)
                ->sum(fn ($i) => $i->remaining_amount);

            $totalOrdered += $total;
            $totalPaid += $paid;
            $totalOverdue += $overdue;

            $rows[] = [
                'reference' => $po->reference,
                'status' => $po->status,
                'date' => $po->created_at->format('M d, Y'),
                'currency' => $currency,
                'total' => Money::format($total),
                'paid' => Money::format($paid),
                'remaining' => Money::format($remaining),
                'overdue' => $overdue > 0 ? Money::format($overdue) : null,
                'url' => route('filament.admin.resources.purchase-orders.view', $po),
            ];
        }

        $unallocatedPayments = $this->getUnallocatedPayments($company->id, PaymentDirection::OUTBOUND);

        return [
            'title' => 'Supplier Statement — Payables',
            'icon' => 'heroicon-o-arrow-up-right',
            'color' => 'danger',
            'rows' => $rows,
            'summary' => [
                'total_invoiced' => Money::format($totalOrdered),
                'total_paid' => Money::format($totalPaid),
                'total_remaining' => Money::format(max(0, $totalOrdered - $totalPaid)),
                'total_overdue' => $totalOverdue > 0 ? Money::format($totalOverdue) : null,
            ],
            'unallocated_payments' => $unallocatedPayments,
            'type' => 'supplier',
        ];
    }

    private function getUnallocatedPayments(int $companyId, PaymentDirection $direction): array
    {
        return Payment::where('company_id', $companyId)
            ->where('direction', $direction)
            ->where('status', PaymentStatus::APPROVED)
            ->get()
            ->filter(fn ($p) => $p->unallocated_amount > 0)
            ->map(fn ($p) => [
                'reference' => $p->reference ?? 'PAY-' . $p->id,
                'date' => $p->payment_date?->format('M d, Y') ?? $p->created_at->format('M d, Y'),
                'currency' => $p->currency_code ?? 'USD',
                'total' => Money::format($p->amount),
                'unallocated' => Money::format($p->unallocated_amount),
                'url' => route('filament.admin.resources.payments.view', $p),
            ])
            ->values()
            ->toArray();
    }
}
