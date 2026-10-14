<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\ClientProductStats;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Filament\Resources\CRM\Companies\RelationManagers\ClientProductsRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Visão geral do produto na aba Products (Client): quantidades e preços
 * vêm das PIs do cliente a partir de Confirmed (Draft/Sent/Cancelled não
 * contam), custo vem da PO ligada ao item da PI, embarcado dos shipments
 * não cancelados.
 */
class ClientProductStatsTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Gate::before(fn () => true);

        $this->client = Company::factory()->create(['name' => 'Deep Fitness']);
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->supplier = Company::factory()->create(['name' => 'Yinqian']);

        $this->product = Product::factory()->create(['name' => 'Treadmill', 'sku' => 'TRD-01']);
        CompanyProduct::create([
            'company_id' => $this->client->id,
            'product_id' => $this->product->id,
            'role' => 'client',
            'unit_price' => 0,
        ]);
    }

    private function piWithItem(string $status, string $issueDate, int $qty, float $price, float $cost = 0): ProformaInvoiceItem
    {
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'status' => $status,
            'issue_date' => $issueDate,
            'currency_code' => 'USD',
        ]);

        return ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Treadmill',
            'quantity' => $qty,
            'unit' => 'pcs',
            'unit_price' => Money::toMinor($price),
            'unit_cost' => Money::toMinor($cost),
            'cost_currency_code' => 'USD',
            'supplier_company_id' => $this->supplier->id,
        ]);
    }

    private function poItemFor(ProformaInvoiceItem $piItem, string $status, string $issueDate, float $cost, string $currency = 'USD'): PurchaseOrderItem
    {
        $po = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $piItem->proforma_invoice_id,
            'supplier_company_id' => $this->supplier->id,
            'status' => $status,
            'issue_date' => $issueDate,
            'currency_code' => $currency,
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Treadmill',
            'quantity' => $piItem->quantity,
            'unit' => 'pcs',
            'unit_cost' => Money::toMinor($cost),
        ]);
    }

    public function test_stats_aggregate_confirmed_pis_po_costs_and_shipped_quantities(): void
    {
        $first = $this->piWithItem('confirmed', '2026-03-01', 10, 100.00);
        $latest = $this->piWithItem('shipped', '2026-09-01', 5, 130.00);
        $this->piWithItem('draft', '2026-10-01', 99, 999.00);   // fora
        $this->piWithItem('sent', '2026-10-02', 99, 999.00);    // fora
        $this->piWithItem('cancelled', '2026-10-03', 99, 999.00); // fora

        $this->poItemFor($first, PurchaseOrderStatus::COMPLETED->value, '2026-03-05', 70.00);
        $poLatest = $this->poItemFor($latest, PurchaseOrderStatus::CONFIRMED->value, '2026-09-05', 85.00);
        $this->poItemFor($latest, PurchaseOrderStatus::CANCELLED->value, '2026-09-20', 500.00); // fora

        $shipment = Shipment::factory()->create(['company_id' => $this->client->id, 'status' => ShipmentStatus::IN_TRANSIT->value]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $first->id,
            'purchase_order_item_id' => $poLatest->id,
            'quantity' => 7,
            'unit' => 'pcs',
        ]);
        $cancelled = Shipment::factory()->create(['company_id' => $this->client->id, 'status' => ShipmentStatus::CANCELLED->value]);
        ShipmentItem::create([
            'shipment_id' => $cancelled->id,
            'proforma_invoice_item_id' => $latest->id,
            'quantity' => 5,
            'unit' => 'pcs',
        ]);

        $stats = ClientProductStats::forCompany($this->client)[$this->product->id];

        $this->assertSame(15, $stats['purchased_qty']);
        $this->assertSame(7, $stats['shipped_qty']);
        $this->assertSame(2, $stats['pi_count']);
        $this->assertSame('2026-09-01', $stats['last_purchase_date']);
        $this->assertSame('USD', $stats['price_currency']);
        $this->assertSame(Money::toMinor(130.00), $stats['last_price']);
        $this->assertSame(Money::toMinor(130.00), $stats['max_price']);
        // (10 × 100 + 5 × 130) / 15 = 110
        $this->assertSame(Money::toMinor(110.00), $stats['avg_price']);
        $this->assertSame('USD', $stats['cost_currency']);
        $this->assertSame(Money::toMinor(85.00), $stats['last_cost']);
        $this->assertSame(Money::toMinor(85.00), $stats['max_cost']);
        $this->assertSame('Yinqian', $stats['last_supplier']);
        // (130 − 85) / 130
        $this->assertEqualsWithDelta(34.6, $stats['last_margin_pct'], 0.05);
    }

    public function test_cost_falls_back_to_the_pi_item_cost_when_there_is_no_po(): void
    {
        $this->piWithItem('confirmed', '2026-03-01', 10, 100.00, 60.00);

        $stats = ClientProductStats::forCompany($this->client)[$this->product->id];

        $this->assertSame(Money::toMinor(60.00), $stats['last_cost']);
        $this->assertSame(Money::toMinor(60.00), $stats['max_cost']);
        $this->assertSame('Yinqian', $stats['last_supplier']);
        $this->assertEqualsWithDelta(40.0, $stats['last_margin_pct'], 0.01);
    }

    public function test_max_cost_only_compares_the_currency_of_the_latest_po(): void
    {
        $first = $this->piWithItem('confirmed', '2026-03-01', 10, 100.00);
        $latest = $this->piWithItem('confirmed', '2026-09-01', 5, 100.00);

        $this->poItemFor($first, PurchaseOrderStatus::COMPLETED->value, '2026-03-05', 600.00, 'CNY');
        $this->poItemFor($latest, PurchaseOrderStatus::CONFIRMED->value, '2026-09-05', 80.00, 'USD');

        $stats = ClientProductStats::forCompany($this->client)[$this->product->id];

        $this->assertSame('USD', $stats['cost_currency']);
        $this->assertSame(Money::toMinor(80.00), $stats['max_cost']);
        $this->assertTrue($stats['mixed_cost_currencies']);
    }

    public function test_product_without_purchases_has_empty_stats(): void
    {
        $stats = ClientProductStats::forCompany($this->client);

        $this->assertArrayNotHasKey($this->product->id, $stats);
    }

    public function test_relation_manager_shows_the_overview_columns(): void
    {
        $first = $this->piWithItem('confirmed', '2026-03-01', 10, 100.00);
        $latest = $this->piWithItem('shipped', '2026-09-01', 5, 130.00);
        $this->poItemFor($latest, PurchaseOrderStatus::CONFIRMED->value, '2026-09-05', 85.00);
        $shipment = Shipment::factory()->create(['company_id' => $this->client->id, 'status' => ShipmentStatus::ARRIVED->value]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $first->id,
            'quantity' => 10,
            'unit' => 'pcs',
        ]);

        Livewire::test(ClientProductsRelationManager::class, [
            'ownerRecord' => $this->client,
            'pageClass' => EditCompany::class,
        ])
            ->assertCanSeeTableRecords([$this->product])
            ->assertTableColumnFormattedStateSet('stats.purchased_qty', '15', $this->product)
            ->assertTableColumnFormattedStateSet('stats.shipped_qty', '10', $this->product)
            ->assertTableColumnFormattedStateSet('stats.last_price', 'USD 130.00', $this->product)
            ->assertTableColumnFormattedStateSet('stats.max_price', 'USD 130.00', $this->product)
            ->assertTableColumnFormattedStateSet('stats.avg_price', 'USD 110.00', $this->product)
            ->assertTableColumnFormattedStateSet('stats.last_cost', 'USD 85.00', $this->product)
            ->assertTableColumnFormattedStateSet('stats.max_cost', 'USD 85.00', $this->product)
            ->assertTableColumnFormattedStateSet('stats.last_margin_pct', '34.6%', $this->product)
            ->assertTableColumnFormattedStateSet('stats.last_purchase_date', '01/09/2026', $this->product)
            ->assertTableColumnFormattedStateSet('stats.pi_count', '2', $this->product);
    }
}
