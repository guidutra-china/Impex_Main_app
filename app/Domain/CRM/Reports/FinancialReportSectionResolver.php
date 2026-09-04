<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Reports\FinancialSectionBuilders\AdditionalCostFinancialSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\CreditNoteSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\DebitNoteSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\FinancialSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\MarginAnalysisSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\PaymentSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\PiFinancialSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\PoFinancialSectionBuilder;
use App\Domain\CRM\Reports\FinancialSectionBuilders\ShipmentCostSectionBuilder;

final class FinancialReportSectionResolver
{
    /**
     * @param  'admin'|'client'|'supplier'  $context
     * @return list<FinancialSectionBuilder>
     */
    public function resolve(CompanyRole $role, string $context): array
    {
        return match ($context) {
            'admin' => $this->adminSections($role),
            'client' => $this->clientSections(),
            'supplier' => $this->supplierSections(),
            default => [],
        };
    }

    /** @return list<FinancialSectionBuilder> */
    private function adminSections(CompanyRole $role): array
    {
        $sections = [];

        if ($role === CompanyRole::CLIENT) {
            $sections[] = new PiFinancialSectionBuilder;
            $sections[] = new PoFinancialSectionBuilder;
            $sections[] = new ShipmentCostSectionBuilder(CompanyRole::CLIENT);
            $sections[] = new PaymentSectionBuilder;
            $sections[] = new DebitNoteSectionBuilder;
            $sections[] = new CreditNoteSectionBuilder;
            $sections[] = new AdditionalCostFinancialSectionBuilder;
            $sections[] = new MarginAnalysisSectionBuilder;
        } elseif ($role === CompanyRole::SUPPLIER) {
            $sections[] = new PoFinancialSectionBuilder;
            $sections[] = new ShipmentCostSectionBuilder(CompanyRole::SUPPLIER);
            $sections[] = new PaymentSectionBuilder;
            $sections[] = new DebitNoteSectionBuilder;
            $sections[] = new CreditNoteSectionBuilder;
            $sections[] = new AdditionalCostFinancialSectionBuilder;
        } elseif ($role === CompanyRole::FORWARDER) {
            // Forwarder-only company: what Impex owes it per shipment
            // (forwarder leg + any supplier leg it carries) and the payments.
            $sections[] = new ShipmentCostSectionBuilder(CompanyRole::FORWARDER);
            $sections[] = new PaymentSectionBuilder;
        }

        return $sections;
    }

    /** @return list<FinancialSectionBuilder> */
    private function clientSections(): array
    {
        return [
            new PiFinancialSectionBuilder,
            new ShipmentCostSectionBuilder(CompanyRole::CLIENT),
            new PaymentSectionBuilder,
            new DebitNoteSectionBuilder,
            new CreditNoteSectionBuilder,
            new AdditionalCostFinancialSectionBuilder,
        ];
    }

    /** @return list<FinancialSectionBuilder> */
    private function supplierSections(): array
    {
        return [
            new PoFinancialSectionBuilder,
            new ShipmentCostSectionBuilder(CompanyRole::SUPPLIER),
            new PaymentSectionBuilder,
            new DebitNoteSectionBuilder,
            new CreditNoteSectionBuilder,
            new AdditionalCostFinancialSectionBuilder,
        ];
    }
}
