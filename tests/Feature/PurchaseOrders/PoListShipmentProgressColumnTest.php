<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lista de POs ganha a coluna "% enviado" da lista de PIs, com a mesma regra:
 * só embarque IN_TRANSIT/ARRIVED conta.
 */
class PoListShipmentProgressColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    private function poWithItem(int $qty): array
    {
        $pi = ProformaInvoice::factory()->create();
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Item',
            'quantity' => $qty,
            'unit' => 'pcs',
            'unit_price' => 2000,
        ]);

        $po = PurchaseOrder::factory()->create(['proforma_invoice_id' => $pi->id]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Item',
            'quantity' => $qty,
            'unit' => 'pcs',
            'unit_cost' => 1000,
        ]);

        return [$po, $item];
    }

    private function ship(PurchaseOrderItem $item, ShipmentStatus $status, int $qty): void
    {
        $shipment = Shipment::create([
            'reference' => 'SH-'.uniqid(),
            'company_id' => $item->purchaseOrder->proformaInvoice->company_id,
            'currency_code' => 'USD',
            'status' => $status,
            'transport_mode' => 'sea',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'purchase_order_item_id' => $item->id,
            'proforma_invoice_item_id' => $item->proforma_invoice_item_id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);
    }

    public function test_column_shows_the_shipped_percentage_per_po(): void
    {
        [$half, $halfItem] = $this->poWithItem(100);
        $this->ship($halfItem, ShipmentStatus::IN_TRANSIT, 50);

        [$booked, $bookedItem] = $this->poWithItem(100);
        $this->ship($bookedItem, ShipmentStatus::BOOKED, 100);

        [$done, $doneItem] = $this->poWithItem(10);
        $this->ship($doneItem, ShipmentStatus::ARRIVED, 10);

        $empty = PurchaseOrder::factory()->create();

        Livewire::test(ListPurchaseOrders::class)
            ->assertTableColumnExists('shipment_progress')
            ->assertTableColumnFormattedStateSet('shipment_progress', '50%', record: $half)
            // Reservado mas não partiu não conta — mesma regra da PI.
            ->assertTableColumnFormattedStateSet('shipment_progress', '0%', record: $booked)
            ->assertTableColumnFormattedStateSet('shipment_progress', '100%', record: $done)
            ->assertTableColumnFormattedStateSet('shipment_progress', '0%', record: $empty);
    }
}
