<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Support\AdditionalCostScheduleSync;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the bank fee of a payment in sync with the additional cost that
 * represents it inside a process (PI or Shipment).
 *
 * A bank fee is charged on TOP of the wire amount — the beneficiary receives
 * the full transferred value — so it never touches `payments.amount`. What it
 * does produce is a cost on the process, billed to whoever bears it:
 * CLIENT (a receivable), SUPPLIER (a credit deducted from what we owe them)
 * or COMPANY (recorded on the process, collected from nobody).
 */
class SyncPaymentBankFeeAction
{
    /** Process classes a fee can hang on. */
    private const PROCESS_CLASSES = [
        ProformaInvoice::class,
        Shipment::class,
    ];

    /**
     * @param  array{amount?: mixed, currency_code?: ?string, billable_to?: mixed, process?: ?string, description?: ?string}|null  $fee
     *
     * @throws ValidationException when the change would rewrite settled history
     */
    public function execute(Payment $payment, ?array $fee): ?AdditionalCost
    {
        $existing = $payment->bankFeeCost()->first();

        if (! $this->hasAmount($fee)) {
            if ($existing) {
                $this->assertNotSettled($existing);
                AdditionalCostScheduleSync::removePrimaryLeg($existing);
                $existing->delete();
            }

            return null;
        }

        $process = $this->resolveProcess($fee['process'] ?? null);

        if (! $process) {
            throw ValidationException::withMessages([
                'bank_fee_process' => __('forms.validation.bank_fee_process_required'),
            ]);
        }

        $billableTo = $fee['billable_to'] instanceof BillableTo
            ? $fee['billable_to']
            : BillableTo::from((string) ($fee['billable_to'] ?: BillableTo::CLIENT->value));

        $amountMinor = Money::toMinor((float) $fee['amount']);
        $currencyCode = (string) ($fee['currency_code'] ?: $payment->currency_code);

        // Charging the fee to the supplier means crediting the very company
        // this outbound payment went to; the credit only surfaces in that
        // supplier's list when the cost names them.
        $supplierCompanyId = $billableTo === BillableTo::SUPPLIER ? $payment->company_id : null;

        $payload = [
            'cost_type' => AdditionalCostType::BANK_FEE->value,
            'description' => $this->description($fee, $payment),
            'amount' => $amountMinor,
            'currency_code' => $currencyCode,
            'billable_to' => $billableTo->value,
            'supplier_company_id' => $supplierCompanyId,
            'cost_date' => $payment->payment_date,
            'source_payment_id' => $payment->getKey(),
        ] + $this->conversion($process, $currencyCode, $amountMinor);

        if ($existing) {
            $this->assertMaterialChangeAllowed($existing, $process, $payload);

            // The fee may have been moved to another process; the schedule
            // item follows it, since upsert keys on the cost, not the payable.
            $payload['costable_type'] = get_class($process);
            $payload['costable_id'] = $process->getKey();

            $existing->update($payload);
            $cost = $existing->fresh();
        } else {
            $payload['status'] = AdditionalCostStatus::PENDING->value;
            $cost = $process->additionalCosts()->create($payload);
        }

        if ($billableTo === BillableTo::COMPANY) {
            AdditionalCostScheduleSync::removePrimaryLeg($cost);
        } else {
            AdditionalCostScheduleSync::syncPrimaryLeg($cost, $process);
        }

        return $cost;
    }

    /**
     * @param  array<string, mixed>|null  $fee
     */
    private function hasAmount(?array $fee): bool
    {
        return $fee !== null
            && filled($fee['amount'] ?? null)
            && (float) $fee['amount'] > 0;
    }

    /**
     * @param  array<string, mixed>  $fee
     */
    private function description(array $fee, Payment $payment): string
    {
        $description = trim((string) ($fee['description'] ?? ''));

        if ($description !== '') {
            return mb_substr($description, 0, 255);
        }

        $ref = $payment->reference ?: '#'.$payment->getKey();

        return mb_substr(__('forms.helpers.bank_fee_default_description', ['reference' => $ref]), 0, 255);
    }

    /**
     * "App\Domain\...\ProformaInvoice:12" → the model, or null when the
     * string is malformed, points outside the allowed processes, or the
     * record no longer exists.
     */
    public function resolveProcess(?string $key): ?Model
    {
        if (blank($key) || ! str_contains($key, ':')) {
            return null;
        }

        [$class, $id] = explode(':', $key, 2);

        if (! in_array($class, self::PROCESS_CLASSES, true)) {
            return null;
        }

        return $class::find((int) $id);
    }

    /**
     * Amount expressed in the process currency. Mirrors the conversion the
     * additional-cost form does: an explicit rate is derived from the
     * approved exchange rates, and no rate simply means 1:1.
     *
     * @return array{exchange_rate: ?float, amount_in_document_currency: int}
     */
    private function conversion(Model $process, string $currencyCode, int $amountMinor): array
    {
        $documentCurrency = (string) ($process->currency_code ?? $currencyCode);

        if ($currencyCode === $documentCurrency) {
            return ['exchange_rate' => null, 'amount_in_document_currency' => $amountMinor];
        }

        $inDocument = AdditionalCostScheduleSync::convertCurrency($currencyCode, $documentCurrency, $amountMinor);

        return [
            'exchange_rate' => $inDocument !== $amountMinor ? $inDocument / max($amountMinor, 1) : null,
            'amount_in_document_currency' => $inDocument,
        ];
    }

    /**
     * A settled fee (its schedule item carries cash or credit history) is
     * frozen: it can neither be removed nor have its amount, currency,
     * bearer or process rewritten.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertMaterialChangeAllowed(AdditionalCost $existing, Model $process, array $payload): void
    {
        $movedProcess = $existing->costable_type !== get_class($process)
            || (int) $existing->costable_id !== (int) $process->getKey();

        $changed = $movedProcess
            || (int) $existing->amount !== (int) $payload['amount']
            || $existing->currency_code !== $payload['currency_code']
            || ($existing->billable_to instanceof BillableTo ? $existing->billable_to->value : $existing->billable_to) !== $payload['billable_to'];

        if ($changed) {
            $this->assertNotSettled($existing);
        }
    }

    private function assertNotSettled(AdditionalCost $cost): void
    {
        if ($cost->hasSettlementHistory()) {
            throw ValidationException::withMessages([
                'bank_fee_amount' => __('forms.validation.bank_fee_locked_by_allocations'),
            ]);
        }
    }
}
