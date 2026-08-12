<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
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

class PoItemsSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sorting_by_quantity_and_product_name(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $po = PurchaseOrder::factory()->create(['supplier_company_id' => Company::factory()->create()->id]);

        $rows = [];
        foreach ([['Zebra', 5], ['Alpha', 1], ['Mango', 3]] as $i => [$name, $qty]) {
            $rows[$name] = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => Product::factory()->create(['name' => $name])->id,
                'description' => $name,
                'quantity' => $qty,
                'unit_cost' => 1000,
                'sort_order' => $i,
            ]);
        }

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $po,
            'pageClass' => EditPurchaseOrder::class,
        ]);

        // Default: keeps sort_order (Zebra, Alpha, Mango were created 0,1,2).
        $component->assertCanSeeTableRecords([$rows['Zebra'], $rows['Alpha'], $rows['Mango']], inOrder: true);

        $component->sortTable('quantity')
            ->assertCanSeeTableRecords([$rows['Alpha'], $rows['Mango'], $rows['Zebra']], inOrder: true);

        $component->sortTable('product.name')
            ->assertCanSeeTableRecords([$rows['Alpha'], $rows['Mango'], $rows['Zebra']], inOrder: true);

        $component->sortTable('quantity', 'desc')
            ->assertCanSeeTableRecords([$rows['Zebra'], $rows['Mango'], $rows['Alpha']], inOrder: true);
    }
}
