<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseOrderItemsListColumnsTest extends TestCase
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

    public function test_items_list_renders_shipped_remaining_and_total_summary(): void
    {
        $supplier = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id, 'proforma_invoice_id' => $pi->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 100,
            'unit_cost' => 1000,
            'sort_order' => 0,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $po,
            'pageClass' => EditPurchaseOrder::class,
        ])
            ->assertSuccessful()
            ->assertCanRenderTableColumn('quantity_shipped')
            ->assertCanRenderTableColumn('quantity_remaining')
            ->assertCanRenderTableColumn('line_total');
    }
}
