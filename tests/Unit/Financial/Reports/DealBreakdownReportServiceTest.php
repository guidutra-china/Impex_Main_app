<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Reports\DealBreakdownReportService;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DealScenarioBuilder;
use Tests\TestCase;

class DealBreakdownReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_pi_with_po_no_shipment(): void
    {
        $client = Company::factory()->create(['status' => 'active']);
        $supplier = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-100', totalMinor: 800_000_0, currency: 'USD')
            ->withReceipt(amountMinor: 400_000_0, date: '2026-03-20')
            ->withPo(supplier: $supplier, reference: 'PO-100', totalMinor: 350_000_0, paidMinor: 350_000_0)
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        $this->assertCount(1, $report->deals);
        $deal = $report->deals[0];

        $this->assertSame('PI-100', $deal->pi->reference);
        $this->assertSame(800_000_0, $deal->pi->totalOriginal);
        $this->assertSame(400_000_0, $deal->receipts->paidOriginal);
        $this->assertEqualsWithDelta(50.0, $deal->receipts->percentPaid, 0.01);
        $this->assertCount(1, $deal->purchaseOrders);
        $this->assertSame(350_000_0, $deal->purchaseOrders[0]->paidOriginal);
        $this->assertEmpty($deal->shipments);

        // Cash balance = received - paid suppliers - paid shipments = 400k - 350k - 0 = 50k
        $this->assertSame(50_000_0, $deal->totals->cashBalance);

        // KPIs
        $this->assertSame(400_000_0, $report->kpi->totalReceived);
        $this->assertSame(350_000_0, $report->kpi->totalPaidSuppliers);
        $this->assertSame(0, $report->kpi->totalPaidShipments);
        $this->assertSame(1, $report->kpi->dealCount);
    }

    public function test_shared_shipment_attribution_by_weight(): void
    {
        $client = Company::factory()->create(['status' => 'active']);
        $supplier = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-200', totalMinor: 1_000_000_0)
            ->withPo(supplier: $supplier, reference: 'PO-200', totalMinor: 500_000_0)
            ->withShipment(
                reference: 'SHP-200',
                totalCostMinor: 100_000_0,
                paidMinor: 50_000_0,
                myItemsWeight: 300.0,
                otherItemsWeight: 200.0,
            )
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        $deal = collect($report->deals)->firstWhere(fn ($d) => $d->pi->reference === 'PI-200');

        $this->assertNotNull($deal);
        $this->assertCount(1, $deal->shipments);
        $shipRow = $deal->shipments[0];

        $this->assertEqualsWithDelta(0.6, $shipRow->attributionPct, 0.001);
        $this->assertSame(\App\Domain\Financial\Reports\DTOs\AttributionBasis::WEIGHT, $shipRow->basis);
        $this->assertSame(60_000_0, $shipRow->attributedOriginal);
        $this->assertSame(30_000_0, $shipRow->paidOriginal);
    }

    public function test_draft_and_cancelled_excluded_by_default_status_filter(): void
    {
        $client = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()->forClient($client)->withPi(
            reference: 'PI-DRAFT',
            status: \App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus::DRAFT,
        )->build();

        DealScenarioBuilder::make()->forClient($client)->withPi(
            reference: 'PI-OK',
            status: \App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus::CONFIRMED,
        )->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        $refs = collect($report->deals)->pluck('pi.reference')->all();
        $this->assertNotContains('PI-DRAFT', $refs);
        $this->assertContains('PI-OK', $refs);
    }

    public function test_matrix_client_includes_branch_pis(): void
    {
        $matrix = Company::factory()->create(['status' => 'active', 'parent_company_id' => null]);
        $branch = Company::factory()->create(['status' => 'active', 'parent_company_id' => $matrix->id]);

        DealScenarioBuilder::make()->forClient($matrix)->withPi(reference: 'PI-MATRIX')->build();
        DealScenarioBuilder::make()->forClient($branch)->withPi(reference: 'PI-BRANCH')->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($matrix, $filters);

        $refs = collect($report->deals)->pluck('pi.reference')->all();
        $this->assertContains('PI-MATRIX', $refs);
        $this->assertContains('PI-BRANCH', $refs);
    }
}
