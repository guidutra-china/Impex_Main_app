<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Support\Facades\DB;

class CreateOrUpdateQuotationFromInquiryAction
{
    private const LOCKED_STATUSES = [
        QuotationStatus::SENT,
        QuotationStatus::NEGOTIATING,
        QuotationStatus::APPROVED,
        QuotationStatus::REJECTED,
        QuotationStatus::EXPIRED,
        QuotationStatus::CANCELLED,
    ];

    public function __construct(
        private CurrencyExchangeResolver $fx,
    ) {}

    public function execute(
        Inquiry $inquiry,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers,
        bool $forceNewVersion = false,
    ): Quotation {
        return DB::transaction(function () use (
            $inquiry, $supplierQuotationIds, $commissionType, $commissionRate, $showSuppliers, $forceNewVersion
        ) {
            $existing = $inquiry->quotations()
                ->latest('version')
                ->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true) && ! $forceNewVersion) {
                throw new QuotationLockedException($existing);
            }

            // For locked statuses with forceNewVersion=true, ignore $existing — Tasks 2.9+ will snapshot.
            $existing = $existing && ! in_array($existing->status, self::LOCKED_STATUSES, true)
                ? $existing
                : null;

            if ($existing) {
                $existing->update([
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'currency_code' => $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                    'show_suppliers' => $showSuppliers,
                ]);
                $quotation = $existing;
            } else {
                $quotation = Quotation::create([
                    'reference' => 'Q-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                    'inquiry_id' => $inquiry->id,
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'status' => QuotationStatus::DRAFT,
                    'currency_code' => $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                    'show_suppliers' => $showSuppliers,
                ]);
            }

            $this->syncItems(
                inquiry: $inquiry,
                quotation: $quotation,
                supplierQuotationIds: $supplierQuotationIds,
                commissionType: $commissionType,
                commissionRate: $commissionRate,
            );

            return $quotation->fresh(['items.suppliers']);
        });
    }

    private function syncItems(
        Inquiry $inquiry,
        Quotation $quotation,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
    ): void {
        $sqItemsByProduct = empty($supplierQuotationIds)
            ? collect()
            : SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->where('unit_cost', '>', 0)
                ->with('supplierQuotation.company')
                ->get()
                ->groupBy('product_id');

        $existingItems = $quotation->items()->get()->keyBy('product_id');
        $sortOrder = 0;

        foreach ($inquiry->items as $inquiryItem) {
            $productId = $inquiryItem->product_id;
            $alternatives = $sqItemsByProduct->get($productId, collect());

            // Elect primary by lowest converted cost.
            $quoteCurrency = $quotation->currency_code;
            $referenceDate = optional($quotation->created_at)->toDateString() ?? today()->toDateString();
            $primary = $alternatives->sortBy(function ($sqItem) use ($quoteCurrency, $referenceDate) {
                $resolved = $this->fx->resolve(
                    $sqItem->supplierQuotation->currency_code,
                    $quoteCurrency,
                    $referenceDate,
                );

                return $sqItem->unit_cost * $resolved['rate'];
            })->first();

            $unitCost = $primary?->unit_cost ?? 0;
            $sourceCurrency = $primary?->supplierQuotation?->currency_code ?? $quoteCurrency;
            $resolved = $this->fx->resolve(
                $sourceCurrency,
                $quoteCurrency,
                $referenceDate,
            );
            $rate = $resolved['rate'];
            $convertedCost = (int) round($unitCost * $rate);

            $existingItem = $existingItems->get($productId);
            $itemCommissionRate = $existingItem
                ? (float) $existingItem->commission_rate
                : ($commissionType === CommissionType::EMBEDDED ? $commissionRate : 0);

            $unitPrice = $commissionType === CommissionType::EMBEDDED && $itemCommissionRate > 0
                ? (int) round($convertedCost * (1 + $itemCommissionRate / 100))
                : $convertedCost;

            $payload = [
                'quotation_id' => $quotation->id,
                'product_id' => $productId,
                'supplier_quotation_item_id' => $primary?->id,
                'quantity' => $inquiryItem->quantity,
                'selected_supplier_id' => $primary?->supplierQuotation?->company_id,
                'unit_cost' => $unitCost,
                'cost_currency_code' => $resolved['currency'],
                'cost_exchange_rate' => $rate,
                'commission_rate' => $itemCommissionRate,
                'unit_price' => $unitPrice,
                'sort_order' => $sortOrder++,
            ];

            if ($existingItem) {
                $existingItem->update($payload);
            } else {
                QuotationItem::create($payload);
            }
        }
    }
}
