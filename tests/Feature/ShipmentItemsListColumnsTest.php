<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentItemsListColumnsTest extends TestCase
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

    public function test_items_list_shows_model_number_preferring_client_pivot_code(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'client_reference' => 'CLIENT-REF-9',
        ]);
        $shipment = Shipment::factory()->create(['company_id' => $client->id]);

        // Product with a client-specific code on the pivot → pivot wins.
        $withPivot = Product::factory()->create(['model_number' => 'MOD-A', 'sku' => 'SKU-A']);
        $withPivot->companies()->attach($client->id, ['role' => 'client', 'external_code' => 'EXT-A']);

        // Product without pivot → falls back to its own model_number.
        $plain = Product::factory()->create(['model_number' => 'MOD-B', 'sku' => 'SKU-B']);

        foreach ([$withPivot, $plain] as $i => $product) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => 'Item '.$i,
                'quantity' => 10,
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'sort_order' => $i,
            ]);
        }

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])
            ->assertSuccessful()
            ->assertCanRenderTableColumn('model_no')
            ->assertSee('EXT-A')
            ->assertDontSee('MOD-A') // pivot code takes priority over the product's own model
            ->assertSee('MOD-B')
            // PI Ref column shows the bare client reference, without the label prefix.
            ->assertSee('CLIENT-REF-9')
            ->assertDontSee(__('forms.labels.client_reference').':');
    }

    public function test_unit_price_column_shows_four_decimal_places(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);
        $shipment = Shipment::factory()->create(['company_id' => $client->id]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory()->create()->id,
            'description' => 'Precision item',
            'quantity' => 10,
            // 12345 minor units at SCALE 10000 = 1.2345 — sub-cent precision
            // must survive in the Unit Price column.
            'unit_price' => 12345,
            'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'sort_order' => 0,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])
            ->assertSuccessful()
            ->assertSee('1.2345');
    }
}
