<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentsListValueColumnsTest extends TestCase
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

    public function test_list_shows_products_total_and_client_freight(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);
        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => Money::toMinor(100),
            'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 5,
            'unit' => 'pcs',
        ]);

        // Client-billable freight counts; supplier-billed freight and other cost types do not.
        $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Ocean freight',
            'currency_code' => 'USD',
            'amount' => Money::toMinor(120),
            'amount_in_document_currency' => Money::toMinor(120),
            'billable_to' => BillableTo::CLIENT,
        ]);
        $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Supplier-side freight',
            'currency_code' => 'USD',
            'amount' => Money::toMinor(999),
            'amount_in_document_currency' => Money::toMinor(999),
            'billable_to' => BillableTo::SUPPLIER,
        ]);
        $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::CUSTOMS,
            'description' => 'Customs clearance',
            'currency_code' => 'USD',
            'amount' => Money::toMinor(50),
            'amount_in_document_currency' => Money::toMinor(50),
            'billable_to' => BillableTo::CLIENT,
        ]);

        Livewire::test(ListShipments::class)
            ->assertCanSeeTableRecords([$shipment])
            // 5 pcs × USD 100.00 from the linked PI item.
            ->assertSee('USD 500.00')
            // Only the client-billable freight cost.
            ->assertSee('USD 120.00')
            ->assertDontSee('USD 999.00');
    }
}
