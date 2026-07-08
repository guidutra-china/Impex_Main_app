<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Database\Factories\ProformaInvoiceItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ImportFromPiCrossPoGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    private ProformaInvoice $pi;

    private PurchaseOrder $firstPo;

    private PurchaseOrder $secondPo;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->supplier = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create();
        $this->firstPo = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
        ]);
        $this->secondPo = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
        ]);
    }

    private function makePiItem(string $productName, int $quantity): \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem
    {
        return ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'product_id' => Product::factory()->create(['name' => $productName])->id,
            'quantity' => $quantity,
        ]);
    }

    private function putOnFirstPo($piItem, int $quantity): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $this->firstPo->id,
            'proforma_invoice_item_id' => $piItem->id,
            'product_id' => $piItem->product_id,
            'quantity' => $quantity,
            'unit_cost' => 1000,
            'sort_order' => ($this->firstPo->items()->max('sort_order') ?? 0) + 1,
        ]);
    }

    public function test_import_offers_remaining_balance_against_all_pos_of_the_pi(): void
    {
        // 2 pcs na PI, 1 pc já na primeira PO → sobra 1 para a segunda.
        $split = $this->makePiItem('Widget Split', 2);
        $this->putOnFirstPo($split, 1);

        // Totalmente coberto pela primeira PO → não deve ser oferecido.
        $full = $this->makePiItem('Widget Fully Ordered', 3);
        $this->putOnFirstPo($full, 3);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->secondPo,
            'pageClass' => EditPurchaseOrder::class,
        ])
            ->mountTableAction('importFromPI')
            ->assertMountedActionModalSee('Widget Split — Qty: 2 | Remaining: 1')
            ->assertMountedActionModalDontSee('Widget Fully Ordered')
            ->setTableActionData(['item_ids' => [$split->id], 'only_remaining' => true])
            ->callMountedTableAction();

        $created = $this->secondPo->items()->where('proforma_invoice_item_id', $split->id)->first();
        $this->assertSame(1, (int) $created->quantity);
    }

    public function test_import_skips_items_whose_balance_was_consumed_while_modal_was_open(): void
    {
        $item = $this->makePiItem('Widget Racing', 2);
        $this->putOnFirstPo($item, 1);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->secondPo,
            'pageClass' => EditPurchaseOrder::class,
        ])->mountTableAction('importFromPI');

        // Corrida: com o modal aberto, a primeira PO absorve o saldo.
        $this->putOnFirstPo($item, 1);

        $component
            ->setTableActionData(['item_ids' => [$item->id], 'only_remaining' => true])
            ->callMountedTableAction();

        $this->assertSame(0, $this->secondPo->items()->count());
    }
}
