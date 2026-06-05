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

    public function test_freight_forwarder_split_and_commission(): void
    {
        $client = Company::factory()->create(['status' => 'active']);
        $supplier = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-300', totalMinor: 1_000_000_0, currency: 'USD')
            ->withReceipt(amountMinor: 1_000_000_0, date: '2026-03-20')
            ->withPo(supplier: $supplier, reference: 'PO-300', totalMinor: 600_000_0, paidMinor: 600_000_0)
            ->withForwarderFreightShipment(
                reference: 'SHP-300',
                clientChargeMinor: 120_000_0,
                forwarderCostMinor: 100_000_0,
                forwarderPaidMinor: 100_000_0,
                clientReimbursedMinor: 120_000_0,
            )
            ->withPiCommission(amountMinor: 50_000_0, billable: \App\Domain\Financial\Enums\BillableTo::CLIENT)
            ->withEmbeddedCommissionQuotation(rate: 5.0, subtotalMinor: 1_000_000_0)
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);
        $deal = collect($report->deals)->firstWhere(fn ($d) => $d->pi->reference === 'PI-300');
        $this->assertNotNull($deal);

        // Freight: cost basis = forwarder cost (not the client charge).
        $this->assertCount(1, $deal->shipments);
        $shipRow = $deal->shipments[0];
        $this->assertEqualsWithDelta(1.0, $shipRow->attributionPct, 0.001);
        $this->assertSame(100_000_0, $shipRow->totalCostOriginal);
        $this->assertSame(120_000_0, $shipRow->clientChargeOriginal);
        // Paid Shipments = OUTBOUND (forwarder) only; client reimbursement is inbound.
        $this->assertSame(100_000_0, $shipRow->paidOriginal);
        $this->assertSame(120_000_0, $shipRow->freightReceivedOriginal);

        // Margin = PI(1,000k) + freight charge(120k) - PO(600k) - real freight cost(100k) = 420k
        $this->assertSame(420_000_0, $deal->totals->margin);

        // Cash balance = received(1,000k + 120k reimbursement) - suppliers(600k) - shipments(100k) = 420k
        $this->assertSame(420_000_0, $deal->totals->cashBalance);

        // Total billed to client = PI goods(1,000k) + freight charged(120k) + separate commission(50k) = 1,170k
        $this->assertSame(1_170_000_0, $deal->totals->billedToClientPresentation);
        // Received total = goods paid(1,000k) + freight reimbursement(120k) = 1,120k (< billed → 50k still due)
        $this->assertSame(1_120_000_0, $deal->totals->receivedTotalPresentation);

        // Real gain = commission received(100k) + freight margin(reimbursement 120k - paid 100k = 20k) = 120k
        $this->assertSame(120_000_0, $deal->totals->overallGainPresentation);

        // Commission charged (received) = separate client(50k) + embedded(1,000k × 5% = 50k) = 100k
        $this->assertSame(50_000_0, $deal->commission->receivedSeparatePresentation);
        $this->assertSame(50_000_0, $deal->commission->receivedEmbeddedPresentation);
        $this->assertSame(100_000_0, $deal->commission->receivedPresentation);
        // Paid by client: separate not yet collected (0) + embedded collected with the
        // fully-paid PI (50k) = 50k; outstanding = 50k (the separate commission).
        $this->assertSame(50_000_0, $deal->commission->paidPresentation);
        $this->assertSame(50_000_0, $deal->commission->outstandingPresentation);

        // KPIs aggregate commission.
        $this->assertSame(100_000_0, $report->kpi->totalCommissionReceived);
        $this->assertSame(50_000_0, $report->kpi->totalCommissionPaid);
        $this->assertSame(100_000_0, $report->kpi->totalPaidShipments);
    }

    public function test_commission_paid_tracks_client_separate_payment(): void
    {
        $client = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-400', totalMinor: 100_000_0, currency: 'USD')
            // Separate client commission of 40k; client has paid 25k of it (via its PSI).
            ->withPiCommission(
                amountMinor: 40_000_0,
                billable: \App\Domain\Financial\Enums\BillableTo::CLIENT,
                paidMinor: 25_000_0,
            )
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);
        $deal = collect($report->deals)->firstWhere(fn ($d) => $d->pi->reference === 'PI-400');
        $this->assertNotNull($deal);

        $this->assertSame(40_000_0, $deal->commission->receivedPresentation);
        $this->assertSame(40_000_0, $deal->commission->receivedSeparatePresentation);
        $this->assertSame(0, $deal->commission->receivedEmbeddedPresentation);
        // Client paid 25k of the 40k commission → 15k outstanding.
        $this->assertSame(25_000_0, $deal->commission->paidPresentation);
        $this->assertSame(15_000_0, $deal->commission->outstandingPresentation);

        $this->assertSame(40_000_0, $report->kpi->totalCommissionReceived);
        $this->assertSame(25_000_0, $report->kpi->totalCommissionPaid);
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
