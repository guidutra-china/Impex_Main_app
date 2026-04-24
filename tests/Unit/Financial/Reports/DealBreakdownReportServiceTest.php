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
}
