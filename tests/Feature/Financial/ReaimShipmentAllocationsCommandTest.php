<?php

namespace Tests\Feature\Financial;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReaimShipmentAllocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrder $po;

    private Shipment $empty;

    private Shipment $loaded;

    private PaymentScheduleItem $from;

    private PaymentScheduleItem $to;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client RA-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);
        $supplier = Company::create(['name' => 'Supplier RA-'.uniqid(), 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-RA-'.uniqid(), 'company_id' => $client->id,
            'status' => 'received', 'source' => 'email', 'currency_code' => 'USD',
        ]);
        $pi = ProformaInvoice::create([
            'reference' => 'PI-RA-'.uniqid(), 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'currency_code' => 'USD', 'issue_date' => '2026-08-01', 'status' => 'confirmed',
        ]);
        $this->po = PurchaseOrder::create([
            'reference' => 'PO-RA-'.uniqid(), 'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id, 'status' => 'confirmed', 'currency_code' => 'CNY',
        ]);

        $product = Product::factory()->create();
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id, 'product_id' => $product->id,
            'description' => 'Item', 'quantity' => 10, 'unit_price' => 100000, 'unit' => 'pcs',
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->po->id, 'product_id' => $product->id,
            'proforma_invoice_item_id' => $piItem->id, 'description' => 'Item',
            'quantity' => 10, 'unit' => 'pcs', 'unit_cost' => 50000,
        ]);

        $make = fn () => Shipment::create([
            'reference' => 'SH-RA-'.uniqid(), 'company_id' => $client->id, 'currency_code' => 'USD',
            'status' => 'draft', 'transport_mode' => 'sea', 'destination_port' => 'Paranagua',
        ]);
        $this->empty = $make();
        $this->loaded = $make();

        ShipmentItem::create([
            'shipment_id' => $this->loaded->id, 'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id, 'quantity' => 10, 'sort_order' => 0,
        ]);

        $this->from = $this->parcel($this->empty, PaymentScheduleStatus::PAID);
        $this->to = $this->parcel($this->loaded, PaymentScheduleStatus::PENDING);

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND, 'company_id' => $supplier->id,
            'amount' => 197_400_000, 'currency_code' => 'CNY', 'payment_date' => '2026-06-01',
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id, 'payment_schedule_item_id' => $this->from->id,
            'allocated_amount' => 197_400_000, 'allocated_amount_in_document_currency' => 197_400_000,
        ]);
    }

    private function parcel(Shipment $shipment, PaymentScheduleStatus $status): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class, 'payable_id' => $this->po->id,
            'shipment_id' => $shipment->id, 'label' => '70% — Before Shipment — ['.$shipment->reference.' / '.$this->po->reference.']',
            'percentage' => 70, 'amount' => 197_400_000, 'currency_code' => 'CNY',
            'due_condition' => 'before_shipment', 'status' => $status->value,
            'is_blocking' => false, 'is_credit' => false, 'sort_order' => 1,
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('financial:reaim-shipment-allocations', ['from' => $this->from->id, 'to' => $this->to->id])
            ->assertSuccessful();

        $this->assertSame(1, $this->from->allocations()->count());
        $this->assertSame(0, $this->to->allocations()->count());
    }

    public function test_apply_moves_allocations_and_reconciles_both_parcels(): void
    {
        $this->artisan('financial:reaim-shipment-allocations', ['from' => $this->from->id, 'to' => $this->to->id, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(0, $this->from->allocations()->count());
        $this->assertSame(1, $this->to->allocations()->count());
        $this->assertSame(PaymentScheduleStatus::PAID, $this->to->refresh()->status);
        $this->assertNotSame(PaymentScheduleStatus::PAID, $this->from->refresh()->status);
    }

    public function test_refuses_when_origin_shipment_still_carries_the_document(): void
    {
        $this->artisan('financial:reaim-shipment-allocations', ['from' => $this->to->id, 'to' => $this->from->id, '--apply' => true])
            ->assertFailed();

        $this->assertSame(1, $this->from->allocations()->count());
    }

    public function test_refuses_different_documents(): void
    {
        $other = PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class, 'payable_id' => $this->po->id + 999,
            'shipment_id' => $this->loaded->id, 'label' => 'x', 'percentage' => 70, 'amount' => 197_400_000,
            'currency_code' => 'CNY', 'due_condition' => 'before_shipment', 'status' => 'pending',
            'is_blocking' => false, 'is_credit' => false, 'sort_order' => 1,
        ]);

        $this->artisan('financial:reaim-shipment-allocations', ['from' => $this->from->id, 'to' => $other->id, '--apply' => true])
            ->expectsOutputToContain('documentos diferentes')
            ->assertFailed();
    }
}
