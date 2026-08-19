<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns an AdditionalCost into its primary payment schedule item — the leg
 * that faces the party named by `billable_to` (a client receivable, or a
 * credit against a supplier). The forwarder and supplier-payable legs are
 * handled elsewhere (they only exist for freight / supplier-billed costs).
 *
 * Extracted from AdditionalCostsRelationManager so costs created outside
 * Filament — e.g. the bank fee entered on a payment — produce byte-identical
 * schedule items.
 */
class AdditionalCostScheduleSync
{
    /**
     * Document the primary leg hangs on. Shipment-owned costs always stay on
     * the shipment; PI-owned supplier-billable costs move to that supplier's
     * PO when it exists, falling back to the PI while it doesn't.
     */
    public static function resolvePayable(AdditionalCost $cost, Model $owner, BillableTo $billableTo): ?Model
    {
        if ($owner instanceof Shipment) {
            return $owner;
        }

        if ($billableTo === BillableTo::CLIENT) {
            if ($owner instanceof ProformaInvoice) {
                return $owner;
            }
            if ($owner instanceof PurchaseOrder) {
                return $owner->proformaInvoice;
            }
        }

        if ($billableTo === BillableTo::SUPPLIER) {
            if ($owner instanceof PurchaseOrder) {
                return $owner;
            }
            if ($owner instanceof ProformaInvoice) {
                // PO do MESMO fornecedor do custo; sem PO dele, fica na PI.
                return SyncSupplierPayableScheduleItemAction::resolveSupplierPo($owner, $cost->supplier_company_id) ?? $owner;
            }
        }

        return $owner;
    }

    /**
     * Create or update the schedule item facing the billable party. Costs
     * billed to the supplier — and discounts of any kind — are credits.
     */
    public static function syncPrimaryLeg(AdditionalCost $cost, Model $owner): void
    {
        $billableTo = $cost->billable_to instanceof BillableTo
            ? $cost->billable_to
            : BillableTo::from($cost->billable_to);

        $payable = self::resolvePayable($cost, $owner, $billableTo);

        if (! $payable) {
            return;
        }

        $isCredit = $billableTo === BillableTo::SUPPLIER
            || $cost->cost_type === AdditionalCostType::DISCOUNT;

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getEnglishLabel()
            : $cost->cost_type;

        $label = $isCredit
            ? ($cost->cost_type === AdditionalCostType::DISCOUNT ? 'Discount: ' : 'Credit: ').$cost->description
            : "{$costTypeLabel}: {$cost->description}";

        self::upsertScheduleItem($cost, $payable, [
            'label' => mb_substr($label, 0, 100),
            'amount' => abs($cost->amount_in_document_currency),
            'is_credit' => $isCredit,
            'notes' => $cost->notes,
        ], 'client');
    }

    /**
     * Drop the primary leg — used when the company absorbs the cost, so
     * nothing is collectible. Items already carrying payment history stay.
     */
    public static function removePrimaryLeg(AdditionalCost $cost): void
    {
        PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withoutSideTags()
            ->whereDoesntHave('allocations')
            ->whereDoesntHave('creditAllocations')
            ->delete();
    }

    /**
     * @param  array{label: string, amount: int, is_credit: bool, notes: ?string}  $data
     */
    public static function upsertScheduleItem(AdditionalCost $cost, Model $payable, array $data, string $tag): void
    {
        $query = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id);

        if ($tag === 'forwarder') {
            $query->where('notes', 'LIKE', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%');
        } else {
            $query->withoutSideTags();
        }

        $existing = $query->first();

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->max('sort_order') ?? 0;

        $scheduleData = [
            'payable_type' => get_class($payable),
            'payable_id' => $payable->getKey(),
            'label' => $data['label'],
            'percentage' => 0,
            'amount' => $data['amount'],
            'currency_code' => $payable->currency_code ?? $cost->currency_code ?? 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => $data['is_credit'],
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => $maxSortOrder + 1,
            'notes' => $data['notes'],
        ];

        if ($existing) {
            if ($existing->isPinnedByAllocations()) {
                $existing->update([
                    'label' => $scheduleData['label'],
                    'amount' => $scheduleData['amount'],
                    'is_credit' => $scheduleData['is_credit'],
                    'notes' => $scheduleData['notes'],
                ]);
            } else {
                $existing->update($scheduleData);
            }

            return;
        }

        PaymentScheduleItem::create($scheduleData);
    }

    /**
     * Convert a minor-unit amount using the approved exchange rates, falling
     * back to the original amount when no rate is available.
     */
    public static function convertCurrency(string $fromCode, string $toCode, int $amountMinor): int
    {
        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();

        if ($fromCurrency && $toCurrency) {
            $converted = ExchangeRate::convert(
                $fromCurrency->id,
                $toCurrency->id,
                Money::toMajor($amountMinor)
            );

            if ($converted !== null) {
                return Money::toMinor($converted);
            }
        }

        return $amountMinor;
    }
}
