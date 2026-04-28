<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
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
            $inquiry, $commissionType, $commissionRate, $showSuppliers, $forceNewVersion
        ) {
            $existing = $inquiry->quotations()
                ->latest('version')
                ->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true) && ! $forceNewVersion) {
                throw new QuotationLockedException($existing);
            }

            // Stub — real implementation in Tasks 2.6+.
            return $existing ?? Quotation::create([
                'inquiry_id' => $inquiry->id,
                'company_id' => $inquiry->company_id,
                'status' => QuotationStatus::DRAFT,
                'currency_code' => $inquiry->currency_code,
                'commission_type' => $commissionType,
                'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                'show_suppliers' => $showSuppliers,
            ]);
        });
    }
}
