<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use Database\Factories\PaymentScheduleItemFactory;
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

    public function test_document_level_installment_shows_full_and_prorated_amounts(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00078',
        ]);

        // PI de 1.000.000; só metade embarca => fatia = 500.000.
        $this->ship($shipment, $pi, quantity: 100, unitPrice: 10_000, shippedQuantity: 50);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 1_000_000,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $data = $this->data($shipment);

        $this->assertCount(1, $data['schedule']);
        $row = $data['schedule'][0];

        $this->assertSame('PI-2026-00078', $row['document']);
        $this->assertSame(1_000_000, $row['raw_document_amount'], 'Coluna da parcela cheia na PI.');
        $this->assertSame(500_000, $row['raw_shipment_amount'], 'Coluna da fatia deste embarque.');
        $this->assertTrue($row['is_prorated']);
        $this->assertSame(50, $row['share_percent']);

        $this->assertSame(500_000, $data['raw_schedule_total'], 'O total soma a fatia, não a parcela cheia.');
    }

    public function test_ship_specific_installment_uses_the_same_value_in_both_columns(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'label' => '70% — Shipment Date',
            'percentage' => 70,
            'amount' => 70_000,
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $row = $this->data($shipment)['schedule'][0];

        $this->assertSame(70_000, $row['raw_document_amount']);
        $this->assertSame(70_000, $row['raw_shipment_amount']);
        $this->assertFalse($row['is_prorated']);
    }

    public function test_remaining_rows_and_forwarder_legs_are_excluded(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '70% — Shipment Date [remaining]',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $cost = $this->cost($shipment, BillableTo::CLIENT, 100_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Freight: Air shipping cost',
            'percentage' => 0,
            'amount' => 100_000,
            'due_condition' => null,
        ]);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Freight payable: Forwarder',
            'percentage' => 0,
            'amount' => 95_000,
            'due_condition' => null,
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG.' ',
        ]);

        $data = $this->data($shipment);

        $labels = collect($data['schedule'])->pluck('label')->all();
        $this->assertContains('Freight: Air shipping cost', $labels);
        $this->assertNotContains('70% — Shipment Date [remaining]', $labels);
        $this->assertNotContains('Freight payable: Forwarder', $labels);
        $this->assertSame(100_000, $data['raw_schedule_total']);
    }
}
