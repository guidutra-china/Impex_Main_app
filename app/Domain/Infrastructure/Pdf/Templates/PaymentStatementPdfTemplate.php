<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;

class PaymentStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.payment-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Payment Statement';
    }

    public function getDocumentType(): string
    {
        return 'payment_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "PS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var ProformaInvoice $pi */
        $pi = $this->model;
        $pi->loadMissing(['company', 'items.product.companies', 'additionalCosts']);

        $currencyCode = $pi->currency_code ?? 'USD';

        $identityResolver = ProductIdentityResolver::forClient($pi->company_id);
        $identityResolver->warm($pi->items->map(fn ($item) => $item->product));

        $piItems = $pi->items->sortBy('sort_order')->values()->map(function ($item, int $index) use ($currencyCode, $identityResolver) {
            $identity = $identityResolver->resolve($item->product, lineDescription: $item->description);

            return [
                'index' => $index + 1,
                'product_code' => $identity->codeOr('—'),
                'description' => $this->formatDescription($identity->description ?: $identity->name),
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
            ];
        });

        $scheduleItems = $pi->paymentScheduleItems()
            ->withoutSideTags()
            ->where('is_credit', false)
            ->with('paymentTermStage')
            ->orderBy('sort_order')
            ->get();

        $scheduleRows = $scheduleItems->values()->map(function (PaymentScheduleItem $item, int $index) use ($currencyCode) {
            $cashPaid = max(0, $item->paid_amount - $item->credit_applied_amount);

            return [
                'index' => $index + 1,
                'label' => $item->label ?? $item->paymentTermStage?->name ?? '—',
                'due_date' => $item->due_date ? $this->formatDate($item->due_date) : '—',
                'amount' => $this->formatMoney($item->amount, $currencyCode, 2),
                'paid' => $this->formatMoney($cashPaid, $currencyCode, 2),
                'credit_applied' => $this->formatMoney($item->credit_applied_amount, $currencyCode, 2),
                'balance' => $this->formatMoney($item->remaining_amount, $currencyCode, 2),
                'status' => $item->status->getEnglishLabel(),
                'status_value' => $item->status->value,
            ];
        });

        $scheduleIds = $scheduleItems->pluck('id');

        $cashAllocations = PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleIds)
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment.paymentMethod', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values();

        $paymentRows = $cashAllocations->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'date' => $this->formatDate($allocation->payment->payment_date),
                'reference' => $allocation->payment->reference ?? '—',
                'method' => $allocation->payment->paymentMethod?->name ?? '—',
                'applied_to' => $allocation->scheduleItem?->label ?? '—',
                'amount' => $this->formatMoney($allocation->allocated_amount_in_document_currency, $currencyCode, 2),
            ];
        });

        $creditAllocations = PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleIds)
            ->whereNotNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment', 'creditItem', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values();

        $creditRows = $creditAllocations->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'date' => $this->formatDate($allocation->payment->payment_date),
                'credit' => $allocation->creditItem?->label ?? '—',
                'applied_to' => $allocation->scheduleItem?->label ?? '—',
                'amount' => $this->formatMoney($allocation->allocated_amount_in_document_currency, $currencyCode, 2),
            ];
        });

        $debitNotes = $pi->debitNotes()
            ->where('party_type', PartyType::CLIENT)
            ->where('status', '!=', DebitNoteStatus::CANCELLED)
            ->orderBy('issued_at')
            ->get();

        $debitNoteRows = $debitNotes->values()->map(function ($dn, int $index) use ($currencyCode) {
            $inTotals = $dn->currency_code === $currencyCode;

            return [
                'index' => $index + 1,
                'reference' => $dn->reference,
                'issued_at' => $this->formatDate($dn->issued_at),
                'due_date' => $dn->due_date ? $this->formatDate($dn->due_date) : '—',
                'currency_code' => $dn->currency_code,
                'total' => $this->formatMoney($dn->total_amount, $dn->currency_code, 2),
                'paid' => $this->formatMoney($dn->paid_amount, $dn->currency_code, 2),
                'balance' => $this->formatMoney($dn->remaining_amount, $dn->currency_code, 2),
                'status' => ucwords(str_replace('_', ' ', $dn->status->value)),
                'status_value' => $dn->status->value,
                'in_totals' => $inTotals,
            ];
        });

        // --- Additional costs as visible lines (discounts as negatives) ---
        // Mesma regra dos service fees do PDF da PI: só client-billable, sem
        // WAIVED e sem comissão EMBEDDED (já embutida nos preços unitários).
        // O grand total soma exatamente as linhas exibidas — nada invisível.
        $visibleCosts = $pi->additionalCosts
            ->filter(function ($cost) {
                if ($cost->billable_to !== BillableTo::CLIENT) {
                    return false;
                }
                if ($cost->status === AdditionalCostStatus::WAIVED) {
                    return false;
                }

                return ! ($cost->cost_type === AdditionalCostType::COMMISSION
                    && $cost->commission_mode === CommissionType::EMBEDDED);
            })
            ->values();

        $additionalCostRows = $visibleCosts->map(function ($cost, int $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'type' => $cost->cost_type->getEnglishLabel(),
                'description' => $cost->description ?? '—',
                'amount' => $this->formatMoney($cost->amount_in_document_currency, $currencyCode, 2),
                'raw_amount' => (int) $cost->amount_in_document_currency,
            ];
        });

        // --- Totals (minor units, PI currency) ---
        $piItemsTotal = (int) $pi->items->sum('line_total');
        $clientCostsTotal = (int) $visibleCosts->sum('amount_in_document_currency');
        $piGrandTotal = $piItemsTotal + $clientCostsTotal;

        $sameCurrencyDns = $debitNotes->where('currency_code', $currencyCode);
        $debitNotesTotal = (int) $sameCurrencyDns->sum('total_amount');
        $debitNotesOutstanding = (int) $sameCurrencyDns->sum(fn ($dn) => $dn->remaining_amount);

        $paymentsReceived = (int) $cashAllocations->sum('allocated_amount_in_document_currency')
            + (int) $sameCurrencyDns->sum(fn ($dn) => $dn->paid_amount);
        $creditsApplied = (int) $creditAllocations->sum('allocated_amount_in_document_currency');

        $nonWaived = $scheduleItems->where('status', '!=', PaymentScheduleStatus::WAIVED);
        $scheduleOutstanding = (int) $nonWaived->sum(fn (PaymentScheduleItem $item) => $item->remaining_amount);
        $overdueOutstanding = (int) $scheduleItems
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->sum(fn (PaymentScheduleItem $item) => $item->remaining_amount);

        $outstanding = $scheduleOutstanding + $debitNotesOutstanding;

        return [
            'pi' => [
                'reference' => $pi->reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'currency_code' => $currencyCode,
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
            ],
            'pi_items' => $piItems->toArray(),
            'additional_costs' => $additionalCostRows->toArray(),
            'pi_items_total' => $this->formatMoney($piItemsTotal, $currencyCode, 2),
            'raw_pi_items_total' => $piItemsTotal,
            'schedule' => $scheduleRows->toArray(),
            'payments' => $paymentRows->toArray(),
            'credits' => $creditRows->toArray(),
            'debit_notes' => $debitNoteRows->toArray(),
            'has_foreign_currency_dns' => $debitNoteRows->contains(fn ($row) => ! $row['in_totals']),
            'totals' => [
                'pi_grand_total' => $this->formatMoney($piGrandTotal, $currencyCode, 2),
                'debit_notes' => $this->formatMoney($debitNotesTotal, $currencyCode, 2),
                'has_debit_notes' => $debitNotesTotal > 0,
                'payments_received' => $this->formatMoney($paymentsReceived, $currencyCode, 2),
                'credits_applied' => $this->formatMoney($creditsApplied, $currencyCode, 2),
                'has_credits' => $creditsApplied > 0,
                'outstanding' => $this->formatMoney($outstanding, $currencyCode, 2),
                'has_outstanding' => $outstanding > 0,
                'overdue' => $this->formatMoney($overdueOutstanding, $currencyCode, 2),
                'has_overdue' => $overdueOutstanding > 0,
                'raw_payments_received' => $paymentsReceived,
                'raw_credits_applied' => $creditsApplied,
                'raw_outstanding' => $outstanding,
            ],
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }
}
