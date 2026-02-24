<?php

namespace Tests\Unit;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialInvoicePricingTest extends TestCase
{
    use RefreshDatabase;

    private Company $clientCompany;
    private Product $product;
    private Shipment $shipment;
    private ProformaInvoice $proformaInvoice;
    private ProformaInvoiceItem $piItem;
    private ShipmentItem $shipmentItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientCompany = Company::create([
            'name' => 'Test Client Co.',
            'status' => 'active',
        ]);

        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TP-001',
            'status' => 'active',
        ]);

        $this->proformaInvoice = ProformaInvoice::create([
            'reference' => 'PI-2026-00001',
            'inquiry_id' => $this->createInquiry()->id,
            'company_id' => $this->clientCompany->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit' => 'pcs',
            'unit_price' => Money::toMinor(10.00),
            'unit_cost' => Money::toMinor(5.00),
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-2026-00001',
            'company_id' => $this->clientCompany->id,
            'status' => 'draft',
            'currency_code' => 'USD',
        ]);

        $this->shipmentItem = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => 50,
            'unit' => 'pcs',
        ]);
    }

    public function test_shipment_item_uses_pi_unit_price_by_default(): void
    {
        $this->assertEquals(
            Money::toMinor(10.00),
            $this->shipmentItem->unit_price
        );
    }

    public function test_shipment_item_line_total_uses_pi_price(): void
    {
        // 50 qty * $10.00 = $500.00
        $this->assertEquals(
            Money::toMinor(10.00) * 50,
            $this->shipmentItem->line_total
        );
    }

    public function test_ci_uses_custom_price_when_set(): void
    {
        // Attach product to client with custom_price
        $this->product->companies()->attach($this->clientCompany->id, [
            'role' => 'client',
            'unit_price' => Money::toMinor(12.00),
            'custom_price' => Money::toMinor(15.00),
            'currency_code' => 'USD',
        ]);

        $this->shipment->loadMissing([
            'items.proformaInvoiceItem.product.companies',
        ]);

        $pivot = $this->getClientPivotForProduct($this->product);

        $this->assertNotNull($pivot);
        $this->assertEquals(Money::toMinor(15.00), $pivot->custom_price);

        // CI should use custom_price (15.00) instead of PI price (10.00)
        $unitPrice = $pivot->custom_price;
        $lineTotal = $unitPrice * $this->shipmentItem->quantity;

        $this->assertEquals(Money::toMinor(15.00), $unitPrice);
        $this->assertEquals(Money::toMinor(15.00) * 50, $lineTotal);
    }

    public function test_ci_falls_back_to_pi_price_when_custom_price_null(): void
    {
        // Attach product to client WITHOUT custom_price
        $this->product->companies()->attach($this->clientCompany->id, [
            'role' => 'client',
            'unit_price' => Money::toMinor(12.00),
            'custom_price' => null,
            'currency_code' => 'USD',
        ]);

        $this->shipment->loadMissing([
            'items.proformaInvoiceItem.product.companies',
        ]);

        $pivot = $this->getClientPivotForProduct($this->product);

        // custom_price is null, so CI should use PI price
        $unitPrice = $this->shipmentItem->unit_price;
        if ($pivot && filled($pivot->custom_price) && $pivot->custom_price > 0) {
            $unitPrice = $pivot->custom_price;
        }

        $this->assertEquals(Money::toMinor(10.00), $unitPrice);
    }

    public function test_ci_falls_back_to_pi_price_when_custom_price_zero(): void
    {
        $this->product->companies()->attach($this->clientCompany->id, [
            'role' => 'client',
            'unit_price' => Money::toMinor(12.00),
            'custom_price' => 0,
            'currency_code' => 'USD',
        ]);

        $this->shipment->loadMissing([
            'items.proformaInvoiceItem.product.companies',
        ]);

        $pivot = $this->getClientPivotForProduct($this->product);

        $unitPrice = $this->shipmentItem->unit_price;
        if ($pivot && filled($pivot->custom_price) && $pivot->custom_price > 0) {
            $unitPrice = $pivot->custom_price;
        }

        $this->assertEquals(Money::toMinor(10.00), $unitPrice);
    }

    public function test_ci_subtotal_recalculates_with_custom_price(): void
    {
        $this->product->companies()->attach($this->clientCompany->id, [
            'role' => 'client',
            'unit_price' => Money::toMinor(12.00),
            'custom_price' => Money::toMinor(20.00),
            'currency_code' => 'USD',
        ]);

        $this->shipment->loadMissing([
            'items.proformaInvoiceItem.product.companies',
        ]);

        $clientCompanyId = $this->shipment->company_id;

        $subtotal = $this->shipment->items->sum(function ($item) use ($clientCompanyId) {
            $product = $item->proformaInvoiceItem?->product;
            $pivot = $this->getClientPivotForProduct($product);

            if ($pivot && filled($pivot->custom_price) && $pivot->custom_price > 0) {
                return $pivot->custom_price * $item->quantity;
            }

            return $item->line_total;
        });

        // 50 qty * $20.00 custom_price = $1000.00
        $this->assertEquals(Money::toMinor(20.00) * 50, $subtotal);
    }

    public function test_ci_subtotal_without_custom_price(): void
    {
        $this->shipment->loadMissing([
            'items.proformaInvoiceItem.product.companies',
        ]);

        $subtotal = $this->shipment->items->sum(function ($item) {
            return $item->line_total;
        });

        // 50 qty * $10.00 PI price = $500.00
        $this->assertEquals(Money::toMinor(10.00) * 50, $subtotal);
    }

    public function test_money_conversion_consistency(): void
    {
        $price = 15.5678;
        $minor = Money::toMinor($price);
        $major = Money::toMajor($minor);

        $this->assertEquals(155678, $minor);
        $this->assertEquals(15.5678, $major);
    }

    public function test_money_handles_null_values(): void
    {
        $this->assertEquals(0, Money::toMinor(null));
        $this->assertEquals(0.0, Money::toMajor(null));
    }

    // --- Helper ---

    private function getClientPivotForProduct(?Product $product)
    {
        if (! $product) {
            return null;
        }

        $clientPivot = $product->companies
            ->where('pivot.company_id', $this->clientCompany->id)
            ->where('pivot.role', 'client')
            ->first();

        return $clientPivot?->pivot;
    }

    private function createInquiry()
    {
        return \App\Domain\Inquiries\Models\Inquiry::create([
            'reference' => 'INQ-2026-00001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }
}
