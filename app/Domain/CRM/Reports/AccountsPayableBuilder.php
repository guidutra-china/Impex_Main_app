<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\AccountsPayableReport;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;

final class AccountsPayableBuilder
{
    public function build(
        Company $company,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        bool $openOnly = true,
        string $locale = 'en',
    ): AccountsPayableReport {
        $query = PaymentScheduleItem::query()
            ->whereIn('payable_type', [ProformaInvoice::class, Shipment::class])
            ->where('is_credit', false)
            // Shipment schedule items tagged [forwarder-payable] are our outbound
            // debts to the forwarder — not the client's accounts payable.
            ->where(function ($q) {
                $q->whereNull('notes')
                    ->orWhere('notes', 'NOT LIKE', '%[forwarder-payable]%');
            })
            ->where(function ($outer) use ($company) {
                $outer->where(function ($q) use ($company) {
                    $q->where('payable_type', ProformaInvoice::class)
                        ->whereHasMorph(
                            'payable',
                            [ProformaInvoice::class],
                            fn ($sub) => $sub->where('company_id', $company->id),
                        );
                })->orWhere(function ($q) use ($company) {
                    $q->where('payable_type', Shipment::class)
                        ->whereHasMorph(
                            'payable',
                            [Shipment::class],
                            fn ($sub) => $sub->where('company_id', $company->id),
                        );
                });
            })
            ->with(['payable', 'allocations.payment'])
            ->orderBy('due_date');

        if ($from !== null) {
            $query->where('due_date', '>=', $from->toDateString());
        }
        if ($to !== null) {
            $query->where('due_date', '<=', $to->toDateString());
        }

        if ($openOnly) {
            $query->whereNotIn('status', [
                PaymentScheduleStatus::PAID,
                PaymentScheduleStatus::WAIVED,
            ]);
        } else {
            // Still exclude waived (they're cancelled) but include paid for historical view
            $query->whereNot('status', PaymentScheduleStatus::WAIVED);
        }

        $items = $query->get();

        $rows = [];
        $totalsByCurrency = [];

        foreach ($items as $item) {
            $parent = $item->payable;
            if (! $parent) {
                continue;
            }

            $amount = (int) $item->amount;
            $paid = (int) $item->paid_amount;
            $balance = max(0, $amount - $paid);

            // If open-only, skip items fully paid (100-minor-unit tolerance for rounding).
            // Also filter here (in addition to status WHERE) because Shipment items often
            // don't have their status auto-synced when payments hit mirror PI items.
            if ($openOnly && $balance <= 100) {
                continue;
            }

            $currency = (string) ($item->currency_code ?? $parent->currency_code ?? 'USD');
            $status = $this->deriveStatus($item, $amount, $paid);

            $rows[] = [
                'document' => $this->documentLabel($parent),
                'client_reference' => (string) ($parent->client_reference ?? ''),
                'installment' => (string) ($item->label ?? __('client_accounts_payable.columns.installment')),
                'due_date' => optional($item->due_date)->format('Y-m-d'),
                'amount' => round($amount / Money::SCALE, 2),
                'paid' => round($paid / Money::SCALE, 2),
                'balance' => round($balance / Money::SCALE, 2),
                'status' => $status,
                'currency' => $currency,
                'days_overdue' => $this->daysOverdue($item->due_date, $balance),
            ];

            if (! isset($totalsByCurrency[$currency])) {
                $totalsByCurrency[$currency] = ['amount' => 0, 'paid' => 0, 'balance' => 0];
            }
            $totalsByCurrency[$currency]['amount'] += $amount;
            $totalsByCurrency[$currency]['paid'] += $paid;
            $totalsByCurrency[$currency]['balance'] += $balance;
        }

        $totals = [];
        foreach ($totalsByCurrency as $currency => $t) {
            $totals[] = [
                'currency' => $currency,
                'total' => round($t['amount'] / Money::SCALE, 2),
                'paid' => round($t['paid'] / Money::SCALE, 2),
                'balance' => round($t['balance'] / Money::SCALE, 2),
            ];
        }

        return new AccountsPayableReport(
            company: $company,
            generatedAt: CarbonImmutable::now(),
            periodFrom: $from,
            periodTo: $to,
            openOnly: $openOnly,
            locale: $locale,
            rows: $rows,
            totals: $totals,
        );
    }

    private function documentLabel(object $parent): string
    {
        $reference = $parent->reference ?? null;

        if ($parent instanceof ProformaInvoice) {
            return (string) ($reference ?? 'PI-'.$parent->id);
        }

        if ($parent instanceof Shipment) {
            return (string) ($reference ?? 'SH-'.$parent->id);
        }

        return (string) ($reference ?? class_basename($parent).'-'.$parent->getKey());
    }

    private function deriveStatus(PaymentScheduleItem $item, int $amount, int $paid): string
    {
        if ($amount > 0 && ($amount - $paid) <= 100) {
            return PaymentScheduleStatus::PAID->value;
        }
        if ($paid > 0) {
            return 'partial';
        }
        if ($item->due_date && $item->due_date->isPast()) {
            return PaymentScheduleStatus::OVERDUE->value;
        }

        return $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
    }

    private function daysOverdue(mixed $dueDate, int $balance): ?int
    {
        if ($balance <= 100 || ! $dueDate) {
            return null;
        }
        $due = $dueDate instanceof \DateTimeInterface ? $dueDate : null;
        if (! $due) {
            return null;
        }
        $days = (int) now()->startOfDay()->diffInDays($due, false);

        return $days < 0 ? abs($days) : null;
    }
}
