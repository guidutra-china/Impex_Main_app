<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Database\Factories\ProformaInvoiceItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ProformaInvoiceItemsListColumnsTest extends TestCase
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
        $pi = ProformaInvoice::factory()->create();
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'quantity' => 100,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])
            ->assertSuccessful()
            ->assertCanRenderTableColumn('quantity_shipped')
            ->assertCanRenderTableColumn('quantity_remaining')
            ->assertCanRenderTableColumn('line_total');
    }
}
