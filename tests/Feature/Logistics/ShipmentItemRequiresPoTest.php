<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Database\Factories\ProformaInvoiceItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A shipment line must trace back to a PO. Adding a PI item that has no linked
 * PO line is blocked in the shipment Items relation manager.
 */
class ShipmentItemRequiresPoTest extends TestCase
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

    public function test_cannot_add_shipment_item_for_pi_item_without_po(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id, 'status' => 'confirmed']);
        $piItemNoPo = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'quantity' => 100,
        ]);
        $shipment = Shipment::factory()->create(['company_id' => $company->id]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])
            ->callTableAction('create', data: [
                'proforma_invoice_id' => $pi->id,
                'proforma_invoice_item_id' => $piItemNoPo->id,
                'quantity' => 10,
            ])
            ->assertHasTableActionErrors(['proforma_invoice_item_id']);

        $this->assertDatabaseMissing('shipment_items', ['proforma_invoice_item_id' => $piItemNoPo->id]);
    }

    public function test_can_add_shipment_item_for_pi_item_with_po(): void
    {
        $company = Company::factory()->create();
        $supplier = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id, 'status' => 'confirmed']);
        $piItem = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'quantity' => 100,
        ]);
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id, 'proforma_invoice_id' => $pi->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'unit_cost' => 1000,
            'sort_order' => 0,
        ]);
        $shipment = Shipment::factory()->create(['company_id' => $company->id]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])
            ->callTableAction('create', data: [
                'proforma_invoice_id' => $pi->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('shipment_items', [
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $piItem->purchaseOrderItem->id,
        ]);
    }
}
