<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Actions\SyncInquiryFromSupplierQuotationAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Support\Facades\DB;

class CreateQuotationFromSupplierQuotationAction
{
    /** SQs que ainda valem como fonte de custo para a cotação do cliente. */
    private const QUOTABLE_STATUSES = [
        SupplierQuotationStatus::RECEIVED,
        SupplierQuotationStatus::UNDER_ANALYSIS,
        SupplierQuotationStatus::SELECTED,
    ];

    public function __construct(
        private readonly SyncInquiryFromSupplierQuotationAction $inquirySync,
        private readonly CreateOrUpdateQuotationFromInquiryAction $quotationBuilder,
    ) {}

    public function execute(
        SupplierQuotation $sq,
        int $companyId,
        ?int $contactId,
        string $currencyCode,
        CommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers = false,
        ?Incoterm $incoterm = null,
        ?int $paymentTermId = null,
        ?int $validityDays = null,
        bool $forceNewVersion = false,
    ): Quotation {
        return DB::transaction(function () use (
            $sq, $companyId, $contactId, $currencyCode, $commissionType, $commissionRate,
            $showSuppliers, $incoterm, $paymentTermId, $validityDays, $forceNewVersion
        ) {
            $inquiry = $this->inquirySync->execute(
                sq: $sq,
                companyId: $companyId,
                contactId: $contactId,
                currencyCode: $currencyCode,
            );

            $supplierQuotationIds = $inquiry->supplierQuotations()
                ->whereIn('status', array_map(
                    fn (SupplierQuotationStatus $status) => $status->value,
                    self::QUOTABLE_STATUSES,
                ))
                ->pluck('id')
                ->push($sq->id)
                ->unique()
                ->values()
                ->all();

            $quotation = $this->quotationBuilder->execute(
                inquiry: $inquiry->load('items'),
                supplierQuotationIds: $supplierQuotationIds,
                commissionType: $commissionType,
                commissionRate: $commissionRate,
                showSuppliers: $showSuppliers,
                forceNewVersion: $forceNewVersion,
                incoterm: $incoterm,
                preferredSupplierQuotationId: $sq->id,
                currencyCode: $currencyCode,
            );

            $header = [];

            if ($paymentTermId !== null) {
                $header['payment_term_id'] = $paymentTermId;
            }

            if ($validityDays !== null) {
                // O hook `creating` do model só calcula valid_until na criação.
                $header['validity_days'] = $validityDays;
                $header['valid_until'] = today()->addDays($validityDays);
            }

            if ($header !== []) {
                $quotation->update($header);
            }

            if ($sq->status === SupplierQuotationStatus::RECEIVED) {
                $sq->transitionTo(
                    SupplierQuotationStatus::UNDER_ANALYSIS,
                    'Cotação ao cliente '.$quotation->reference.' gerada a partir desta SQ.',
                );
            }

            return $quotation->fresh(['items.suppliers']);
        });
    }
}
