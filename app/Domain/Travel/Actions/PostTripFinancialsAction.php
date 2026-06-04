<?php

namespace App\Domain\Travel\Actions;

use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Models\CompanyExpense;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Travel\DataTransferObjects\TripBillingData;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Models\TripExpense;
use App\Domain\Travel\Support\TripFx;
use Illuminate\Support\Facades\DB;

/**
 * Posts (and re-syncs) the financial effect of an approved trip:
 *  - internal trip  → an approved CompanyExpense per currency (debits the company)
 *  - client/supplier trip → a single draft DebitNote in the chosen billing
 *    currency, itemised per expense and converted at the supplied FX rates
 *
 * Re-running updates the existing documents instead of duplicating them. Debit
 * notes already issued/paid are left untouched to protect the billing pipeline.
 */
class PostTripFinancialsAction
{
    public function execute(Trip $trip, ?TripBillingData $billing = null): void
    {
        DB::transaction(function () use ($trip, $billing) {
            if ($trip->is_internal) {
                $this->syncCompanyExpenses($trip);
            } elseif ($trip->company_id !== null) {
                $this->syncDebitNote($trip, $billing ?? TripBillingData::for($trip));
            }
        });
    }

    private function syncCompanyExpenses(Trip $trip): void
    {
        $totals = $trip->totals_by_currency; // [currency_code => amount in minor units]
        $expenseDate = ($trip->end_date ?? $trip->start_date ?? now())->toDateString();

        foreach ($totals as $currency => $amount) {
            CompanyExpense::updateOrCreate(
                ['reference' => "TRIP-{$trip->id}-{$currency}"],
                [
                    'category' => ExpenseCategory::TRAVEL,
                    'description' => $this->tripDescription($trip),
                    'amount' => $amount,
                    'currency_code' => $currency,
                    'expense_date' => $expenseDate,
                    'status' => ExpenseApprovalStatus::APPROVED,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'notes' => "Gerado automaticamente da viagem #{$trip->id}.",
                ],
            );
        }

        CompanyExpense::where('reference', 'like', "TRIP-{$trip->id}-%")
            ->whereNotIn('currency_code', array_keys($totals))
            ->get()
            ->each
            ->delete();
    }

    private function syncDebitNote(Trip $trip, TripBillingData $billing): void
    {
        $expenses = $trip->expenses;
        $debitNote = DebitNote::where('trip_id', $trip->id)->first();

        // Never overwrite a debit note that already left DRAFT (issued/paid).
        if ($debitNote !== null && $debitNote->status !== DebitNoteStatus::DRAFT) {
            return;
        }

        if ($expenses->isEmpty()) {
            if ($debitNote !== null) {
                $debitNote->lineItems()->delete();
                $debitNote->delete();
            }

            return;
        }

        if ($debitNote === null) {
            $debitNote = DebitNote::create([
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                'currency_code' => $billing->billingCurrency,
                'status' => DebitNoteStatus::DRAFT,
                'notes' => "Despesas de viagem: {$trip->title}.",
            ]);
        } else {
            $debitNote->update(['currency_code' => $billing->billingCurrency]);
            $debitNote->lineItems()->delete();
        }

        foreach ($expenses as $expense) {
            $rate = $billing->rateFor($expense->currency_code);

            DebitNoteLineItem::create([
                'debit_note_id' => $debitNote->id,
                'description' => $this->lineDescription($expense, $rate, $billing->billingCurrency),
                'amount' => TripFx::convertMinor($expense->amount, $rate),
                'currency_code' => $billing->billingCurrency,
            ]);
        }

        $debitNote->recalculateTotal();
    }

    private function tripDescription(Trip $trip): string
    {
        $place = $trip->destination_city ?: $trip->destination_country;

        return trim("Viagem: {$trip->title}".($place ? " ({$place})" : ''));
    }

    private function lineDescription(TripExpense $expense, float $rate, string $billingCurrency): string
    {
        $label = $expense->category->getLabel();
        $description = $expense->description ? " - {$expense->description}" : '';
        $when = $expense->expense_date?->format('d/m H:i');

        // Show the original amount and the rate used when a conversion happened.
        $original = $expense->currency_code.' '.Money::format($expense->amount);
        if ($expense->currency_code !== $billingCurrency) {
            $original .= ' @ '.rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
        }

        return trim("{$label}{$description} [{$original}]".($when ? " ({$when})" : ''));
    }
}
