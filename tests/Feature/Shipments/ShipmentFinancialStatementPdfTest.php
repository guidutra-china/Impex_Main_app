<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentFinancialStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create(['name' => 'Daxion Trading']);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'SH-2026-00041',
        ], $overrides));
    }

    /**
     * Cria uma PI com um item e embarca $shippedQuantity dele.
     */
    private function ship(
        Shipment $shipment,
        ProformaInvoice $pi,
        int $quantity = 100,
        int $unitPrice = 10_000,
        ?int $shippedQuantity = null,
    ): ProformaInvoiceItem {
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Item',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $shippedQuantity ?? $quantity,
            'sort_order' => 1,
        ]);

        return $piItem;
    }

    private function data(Shipment $shipment): array
    {
        return (new ShipmentFinancialStatementPdfTemplate($shipment))->getData();
    }

    public function test_goods_section_has_one_row_per_proforma_invoice_with_shipped_value(): void
    {
        $shipment = $this->makeShipment();

        $piA = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00078',
            'client_reference' => 'Daxion - 4th order',
        ]);
        $piB = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00079',
        ]);

        $this->ship($shipment, $piA, quantity: 100, unitPrice: 10_000);
        $this->ship($shipment, $piB, quantity: 10, unitPrice: 5_000);

        $data = $this->data($shipment);

        $this->assertCount(2, $data['goods']);

        $rowA = collect($data['goods'])->firstWhere('reference', 'PI-2026-00078');
        $this->assertSame('Daxion - 4th order', $rowA['client_reference']);
        $this->assertSame(1_000_000, $rowA['raw_amount']);
        $this->assertTrue($rowA['in_totals']);

        $this->assertSame(1_050_000, $data['raw_goods_total']);
        $this->assertSame('Daxion Trading', $data['client']['name']);
        $this->assertSame('SH-2026-00041', $data['shipment']['reference']);
    }

    public function test_proforma_invoice_in_another_currency_is_flagged_out_of_totals(): void
    {
        $shipment = $this->makeShipment();

        $usd = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-USD',
        ]);
        $cny = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'CNY',
            'reference' => 'PI-CNY',
        ]);

        $this->ship($shipment, $usd, quantity: 10, unitPrice: 1_000);
        $this->ship($shipment, $cny, quantity: 10, unitPrice: 9_999);

        $data = $this->data($shipment);

        $foreign = collect($data['goods'])->firstWhere('reference', 'PI-CNY');
        $this->assertFalse($foreign['in_totals']);
        $this->assertSame('CNY', $foreign['currency_code']);
        $this->assertTrue($data['has_foreign_currency_pis']);

        $this->assertSame(10_000, $data['raw_goods_total'], 'A PI em CNY não pode entrar no subtotal em USD.');
    }

    private function cost(Shipment $shipment, BillableTo $billableTo, int $amount, array $overrides = []): AdditionalCost
    {
        return AdditionalCost::create(array_merge([
            'costable_type' => Shipment::class,
            'costable_id' => $shipment->id,
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Air shipping cost',
            'amount' => $amount,
            'currency_code' => 'USD',
            'amount_in_document_currency' => $amount,
            'billable_to' => $billableTo,
            'cost_date' => '2026-08-28',
            'status' => AdditionalCostStatus::PENDING,
        ], $overrides));
    }

    public function test_only_client_billable_costs_are_listed(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 1_000);

        $this->cost($shipment, BillableTo::CLIENT, 17_434_000);
        $this->cost($shipment, BillableTo::COMPANY, 999_999, ['description' => 'Internal only']);
        $this->cost($shipment, BillableTo::SUPPLIER, 888_888, ['description' => 'Supplier repass']);

        $data = $this->data($shipment);

        $this->assertCount(1, $data['costs']);
        $this->assertSame('Air shipping cost', $data['costs'][0]['description']);
        $this->assertSame(17_434_000, $data['raw_costs_total']);

        $payload = json_encode($data);
        $this->assertStringNotContainsString('Internal only', $payload);
        $this->assertStringNotContainsString('Supplier repass', $payload);
    }

    public function test_waived_costs_are_not_listed(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 1_000);

        $this->cost($shipment, BillableTo::CLIENT, 500_000, [
            'description' => 'Waived charge',
            'status' => AdditionalCostStatus::WAIVED,
        ]);

        $data = $this->data($shipment);

        $this->assertSame([], $data['costs']);
        $this->assertSame(0, $data['raw_costs_total']);
    }
}
