<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyFinancialReportService;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\Templates\CompanyFinancialStatementPdfTemplate;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom financial report (PDF) for a forwarder-only company: the shipments
 * section must show what Impex owes the forwarder — its [forwarder-payable]
 * freight leg plus any [supplier-payable] leg it carries on other costs
 * (SH-41: export documents invoiced by the freight forwarder).
 */
class ForwarderFinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $forwarder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->forwarder = Company::create(['name' => 'TAS Logistics', 'status' => 'active']);
        $this->forwarder->companyRoles()->create(['role' => 'forwarder']);
    }

    private function makeShipment(string $ref): Shipment
    {
        return Shipment::create([
            'reference' => $ref,
            'company_id' => $this->client->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);
    }

    private function filters(): FinancialReportFilters
    {
        return new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth()->startOfDay(),
            to: CarbonImmutable::now()->endOfDay(),
            statusScope: 'all',
            sectionKeys: ['shipments'],
            currency: null,
            locale: 'en',
            context: 'admin',
        );
    }

    public function test_shipments_section_shows_forwarder_and_supplier_legs_owed_to_forwarder(): void
    {
        $shipment = $this->makeShipment('SHP-TEST-041');

        // Client is billed 1,743.40 for freight; Impex owes the forwarder 1,705.00.
        $freight = $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT->value,
            'description' => 'Air freight',
            'amount' => 17_434_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 17_434_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
            'cost_date' => now()->subDays(4)->toDateString(),
            'forwarder_company_id' => $this->forwarder->id,
            'forwarder_amount' => 17_050_000,
            'forwarder_currency_code' => 'USD',
            'forwarder_amount_in_document_currency' => 17_050_000,
        ]);
        PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Freight payable: TAS Logistics - Air freight',
            'percentage' => 0,
            'amount' => 17_050_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG,
            'source_type' => AdditionalCost::class,
            'source_id' => $freight->id,
        ]);

        // Client is billed 426.00 for documents; Impex owes the forwarder 78.69.
        $docs = $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Export documents',
            'amount' => 4_260_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 4_260_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
            'cost_date' => now()->subDays(3)->toDateString(),
            'supplier_company_id' => $this->forwarder->id,
            'supplier_payable_amount' => 786_900,
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 786_900,
        ]);
        app(SyncSupplierPayableScheduleItemAction::class)->execute($docs, $shipment);

        // A shipment the forwarder has nothing to do with must stay out.
        $this->makeShipment('SHP-TEST-099');

        $report = app(CompanyFinancialReportService::class)->build($this->forwarder, $this->filters());

        $section = collect($report->sections)->firstWhere('key', 'shipments');
        $this->assertNotNull($section, 'shipments section missing for forwarder-only company');

        $headers = collect($section->rows)->where('_row_type', 'header')->values();
        $this->assertCount(1, $headers);
        $header = $headers->first();
        $this->assertSame($shipment->id, $header['_entity_id']);
        $this->assertSame(1705.00, $header['freight']);
        $this->assertSame(78.69, $header['other_costs']);
        $this->assertSame(1783.69, $header['total_costs']);
        $this->assertSame(0.0, $header['paid']);
        $this->assertSame(1783.69, $header['balance']);

        $details = collect($section->rows)->where('_row_type', 'detail')->values();
        $this->assertCount(2, $details);
        $this->assertSame([1705.00, 78.69], $details->pluck('total_costs')->all());

        // The report still renders to a PDF document for a forwarder-only company.
        $document = app(PdfGeneratorService::class)->generate(new CompanyFinancialStatementPdfTemplate(
            model: $this->forwarder,
            locale: 'en',
            options: ['report' => $report],
        ));
        $this->assertInstanceOf(Document::class, $document);
    }
}
