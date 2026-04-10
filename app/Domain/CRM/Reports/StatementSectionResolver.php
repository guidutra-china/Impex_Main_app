<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Reports\SectionBuilders\InquirySectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ProformaInvoiceSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\PurchaseOrderSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\QuotationSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\RfqSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\SectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ShipmentSectionBuilder;

final class StatementSectionResolver
{
    /** @return list<SectionBuilder> */
    public function resolve(CompanyRole $role): array
    {
        return match ($role) {
            CompanyRole::CLIENT => [
                new InquirySectionBuilder(),
                new QuotationSectionBuilder(),
                new ProformaInvoiceSectionBuilder(),
                new PurchaseOrderSectionBuilder(CompanyRole::CLIENT),
                new ShipmentSectionBuilder(CompanyRole::CLIENT),
            ],
            CompanyRole::SUPPLIER => [
                new RfqSectionBuilder(),
                new PurchaseOrderSectionBuilder(),
                new ShipmentSectionBuilder(CompanyRole::SUPPLIER),
            ],
            CompanyRole::FORWARDER => [
                new ShipmentSectionBuilder(CompanyRole::FORWARDER),
            ],
            default => [],
        };
    }
}
