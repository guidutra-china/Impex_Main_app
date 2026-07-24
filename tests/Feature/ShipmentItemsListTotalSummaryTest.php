<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Database\Factories\ProformaInvoiceItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentItemsListTotalSummaryTest extends TestCase
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

    public function test_items_list_shows_the_sum_of_line_totals_in_the_footer(): void
    {
        $company = Company::factory()->create();
        $shipment = Shipment::factory()->create(['company_id' => $company->id]);
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        // 10 × 100.00 = 1,000.00 and 10 × 23.45 = 234.50 → footer sum 1,234.50
        // (a value that appears in no individual row, only in the summary).
        foreach ([1000000, 234500] as $i => $unitPrice) {
            $piItem = ProformaInvoiceItemFactory::new()->create([
                'proforma_invoice_id' => $pi->id,
                'quantity' => 10,
                'unit_price' => $unitPrice,
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
            ->assertCanRenderTableColumn('line_total')
            ->assertSeeText('1,234.50');
    }
}
