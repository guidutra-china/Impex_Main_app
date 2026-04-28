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
        array $itemOverrides = [],
    ): Quotation {
        return DB::transaction(function () use (
            $inquiry, $supplierQuotationIds, $commissionType, $commissionRate, $showSuppliers, $forceNewVersion, $itemOverrides
        ) {
            $existing = $inquiry->quotations()->latest('version')->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true)) {
                if (! $forceNewVersion) {
                    throw new QuotationLockedException($existing);
                }

                \App\Domain\Quotations\Models\QuotationVersion::create([
                    'quotation_id' => $existing->id,
                    'version' => $existing->version,
                    'snapshot' => $this->snapshotQuotation($existing),
                ]);

                $existing = null;
                $newVersion = ($inquiry->quotations()->max('version') ?? 0) + 1;
            } else {
                $newVersion = 1;
            }

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
                    'version' => $newVersion,
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
                itemOverrides: $itemOverrides,
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
        array $itemOverrides = [],
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

            $override = $itemOverrides[$inquiryItem->id] ?? null;
            if ($override) {
                $unitCost = (int) round(($override['unit_cost'] ?? 0) * 10000);
                $sourceCurrency = $override['cost_currency_code'] ?? $sourceCurrency;
                $rate = (float) ($override['cost_exchange_rate'] ?? $rate);
                $itemCommissionRate = (float) ($override['commission_rate'] ?? $itemCommissionRate);
                $resolved = ['currency' => $sourceCurrency, 'rate' => $rate];
                $convertedCost = (int) round($unitCost * $rate);

                $overrideUnitPrice = isset($override['unit_price']) ? (float) $override['unit_price'] : null;
                if ($overrideUnitPrice !== null && $overrideUnitPrice > 0) {
                    $unitPrice = (int) round($overrideUnitPrice * 10000);
                } else {
                    $unitPrice = $commissionType === CommissionType::EMBEDDED && $itemCommissionRate > 0
                        ? (int) round($convertedCost * (1 + $itemCommissionRate / 100))
                        : $convertedCost;
                }
            }

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

            $persistedItem = $existingItem ?: \App\Domain\Quotations\Models\QuotationItem::where('quotation_id', $quotation->id)
                ->where('product_id', $productId)
                ->latest('id')
                ->first();

            // Sync alternatives — replace any prior set with the current pool.
            $persistedItem->suppliers()->delete();
            foreach ($alternatives as $alt) {
                $altResolved = $this->fx->resolve(
                    $alt->supplierQuotation->currency_code,
                    $quoteCurrency,
                    $referenceDate,
                );
                \App\Domain\Quotations\Models\QuotationItemSupplier::create([
                    'quotation_item_id' => $persistedItem->id,
                    'company_id' => $alt->supplierQuotation->company_id,
                    'unit_cost' => $alt->unit_cost,
                    'currency_code' => $altResolved['currency'],
                    'cost_exchange_rate' => $altResolved['rate'],
                    'lead_time_days' => $alt->lead_time_days,
                    'moq' => $alt->moq,
                ]);
            }
        }

        // Drop QuotationItems whose product is no longer present in the inquiry.
        $inquiryProductIds = $inquiry->items->pluck('product_id')->filter()->toArray();
        $quotation->items()
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $inquiryProductIds)
            ->delete();
    }

    private function snapshotQuotation(Quotation $quotation): array
    {
        return [
            'quotation' => $quotation->toArray(),
            'items' => $quotation->items()->with('suppliers')->get()->toArray(),
        ];
    }
}
