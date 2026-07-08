<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\AgingBuckets;
use App\Domain\CRM\Reports\DTOs\CurrencyTotals;
use App\Domain\CRM\Reports\DTOs\FinancialSummary;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class FinancialSummaryBuilder
{
    public function build(
        Company $company,
        CompanyRole $role,
        StatementFilters $filters,
    ): ?FinancialSummary {
        $documents = match ($role) {
            CompanyRole::CLIENT => $this->loadClientDocuments($company, $filters),
            CompanyRole::SUPPLIER => $this->loadSupplierDocuments($company, $filters),
            default => null,
        };

        if ($documents === null || $documents->isEmpty()) {
            return null;
        }

        $documentType = $role === CompanyRole::CLIENT ? 'proforma_invoices' : 'purchase_orders';

        $totalsByCurrency = $this->totalsByCurrency($documents);
        $agingByCurrency = $this->agingByCurrency($documents);
        $breakdownByDocumentType = $this->breakdownByDocumentType($documents, $documentType);

        if ($totalsByCurrency === []) {
            return null;
        }

        return new FinancialSummary(
            totalsByCurrency: $totalsByCurrency,
            agingByCurrency: $agingByCurrency,
            breakdownByDocumentType: $breakdownByDocumentType,
        );
    }

    private function loadClientDocuments(Company $company, StatementFilters $filters): Collection
    {
        return ProformaInvoice::query()
            ->where('company_id', $company->id)
            // Documento cancelado não entra no balance, independente do statusScope.
            ->where('status', '!=', 'cancelled')
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with(['items', 'paymentScheduleItems.allocations.payment'])
            ->get();
    }

    private function loadSupplierDocuments(Company $company, StatementFilters $filters): Collection
    {
        return PurchaseOrder::query()
            ->where('supplier_company_id', $company->id)
            // Documento cancelado não entra no balance, independente do statusScope.
            ->where('status', '!=', 'cancelled')
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with(['items', 'paymentScheduleItems.allocations.payment'])
            ->get();
    }

    /**
     * @param  Collection<int,ProformaInvoice|PurchaseOrder>  $documents
     * @return list<CurrencyTotals>
     */
    private function totalsByCurrency(Collection $documents): array
    {
        $byCurrency = [];

        foreach ($documents as $doc) {
            $currency = (string) ($doc->currency_code ?? '');
            if ($currency === '') {
                continue;
            }

            $total = (int) ($doc->grand_total ?? $doc->total ?? 0);
            $paid = (int) ($doc->schedule_paid_total ?? 0);
            $waivedOpen = $this->waivedOpenAmount($doc);

            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = ['invoiced' => 0, 'paid' => 0, 'waived' => 0];
            }

            $byCurrency[$currency]['invoiced'] += $total;
            $byCurrency[$currency]['paid'] += $paid;
            $byCurrency[$currency]['waived'] += $waivedOpen;
        }

        $result = [];
        foreach ($byCurrency as $currency => $vals) {
            $invoiced = $vals['invoiced'] / Money::SCALE;
            $paid = $vals['paid'] / Money::SCALE;
            $waived = $vals['waived'] / Money::SCALE;
            $result[] = new CurrencyTotals(
                currency: $currency,
                invoiced: round($invoiced, 2),
                paid: round($paid, 2),
                // Parcela waived é dívida perdoada — sai do saldo em aberto.
                open: round($invoiced - $paid - $waived, 2),
            );
        }

        return $result;
    }

    /**
     * Valor ainda em aberto das parcelas WAIVED (não-crédito) do documento —
     * dívida perdoada que não deve constar no balance.
     */
    private function waivedOpenAmount(ProformaInvoice|PurchaseOrder $doc): int
    {
        return (int) $doc->paymentScheduleItems
            ->where('is_credit', false)
            ->where('status', \App\Domain\Financial\Enums\PaymentScheduleStatus::WAIVED)
            ->sum(fn ($item) => max(0, (int) $item->amount - (int) $item->paid_amount));
    }

    /**
     * @param  Collection<int,ProformaInvoice|PurchaseOrder>  $documents
     * @return list<AgingBuckets>
     */
    private function agingByCurrency(Collection $documents): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $byCurrency = [];

        foreach ($documents as $doc) {
            $currency = (string) ($doc->currency_code ?? '');
            if ($currency === '') {
                continue;
            }

            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
            }

            foreach ($doc->paymentScheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }

                // Waived não envelhece: dívida perdoada fica fora do aging.
                if ($item->status === \App\Domain\Financial\Enums\PaymentScheduleStatus::WAIVED) {
                    continue;
                }

                $itemAmount = (int) $item->amount;
                $allocated = 0;
                foreach ($item->allocations ?? [] as $allocation) {
                    if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                        $allocated += (int) ($allocation->allocated_amount_in_document_currency ?? 0);
                    }
                }

                $open = $itemAmount - $allocated;
                if ($open <= 0 || $item->due_date === null) {
                    continue;
                }

                $dueDate = CarbonImmutable::parse($item->due_date);
                if ($dueDate->greaterThanOrEqualTo($today)) {
                    continue;
                }

                $daysOverdue = (int) $dueDate->diffInDays($today);
                $bucket = match (true) {
                    $daysOverdue <= 30 => '0_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default => '90_plus',
                };

                $byCurrency[$currency][$bucket] += $open;
            }
        }

        $result = [];
        foreach ($byCurrency as $currency => $buckets) {
            $result[] = new AgingBuckets(
                currency: $currency,
                bucket0to30: round($buckets['0_30'] / Money::SCALE, 2),
                bucket31to60: round($buckets['31_60'] / Money::SCALE, 2),
                bucket61to90: round($buckets['61_90'] / Money::SCALE, 2),
                bucket90plus: round($buckets['90_plus'] / Money::SCALE, 2),
            );
        }

        return $result;
    }

    /**
     * @param  Collection<int,ProformaInvoice|PurchaseOrder>  $documents
     * @return array<string,array<string,float>>
     */
    private function breakdownByDocumentType(Collection $documents, string $documentType): array
    {
        $result = [];
        foreach ($documents as $doc) {
            $currency = (string) ($doc->currency_code ?? '');
            if ($currency === '') {
                continue;
            }
            $total = (int) ($doc->grand_total ?? $doc->total ?? 0);
            if (! isset($result[$currency])) {
                $result[$currency] = [$documentType => 0.0];
            }
            $result[$currency][$documentType] += $total / Money::SCALE;
        }

        foreach ($result as $currency => $types) {
            foreach ($types as $type => $val) {
                $result[$currency][$type] = round($val, 2);
            }
        }

        return $result;
    }
}
