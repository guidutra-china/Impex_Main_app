<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ProformaInvoices\Widgets\ShipmentFulfillmentWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PI-2026-00075 / SH-2026-00054: embarque em Customs não conta como enviado
 * (regra mantida), mas também não aparecia em lugar nenhum da PI. A coluna
 * "Em embarque" mostra o que está reservado (BOOKED/CUSTOMS) sem mexer em
 * Shipped nem em Remaining.
 */
class PiInShipmentColumnTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private ProformaInvoiceItem $piItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Client '.uniqid(), 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-'.uniqid(),
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-09-01',
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description' => 'Glass panel',
            'quantity' => 100,
            'unit' => 'pcs',
            'unit_price' => 1_000,
            'unit_cost' => 500,
        ]);
    }

    private function ship(ShipmentStatus $status, int $qty, ?string $bl = null): Shipment
    {
        $shipment = Shipment::create([
            'reference' => 'SH-'.uniqid(),
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => $status,
            'transport_mode' => 'sea',
            'bl_number' => $bl,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);

        return $shipment;
    }

    public function test_accessor_counts_booked_and_customs_only(): void
    {
        $this->ship(ShipmentStatus::BOOKED, 10);
        $this->ship(ShipmentStatus::CUSTOMS, 20);
        $this->ship(ShipmentStatus::DRAFT, 5);
        $this->ship(ShipmentStatus::IN_TRANSIT, 30);
        $this->ship(ShipmentStatus::CANCELLED, 7);

        $item = $this->piItem->fresh();

        $this->assertSame(30, $item->quantity_in_shipment);
        // Regra de enviado intacta: só o in_transit conta, e remaining ignora o em embarque.
        $this->assertSame(30, $item->quantity_shipped);
        $this->assertSame(70, $item->quantity_remaining);
    }

    public function test_admin_widget_exposes_in_shipment_without_touching_shipped_or_remaining(): void
    {
        $this->ship(ShipmentStatus::CUSTOMS, 28, bl: 'LWSCN26247');
        $this->ship(ShipmentStatus::IN_TRANSIT, 12);

        $widget = new ShipmentFulfillmentWidget;
        $widget->record = $this->pi->fresh();
        $data = (fn () => $this->getViewData())->call($widget);

        $this->assertTrue($data['showInShipment']);
        $this->assertSame(28, $data['items'][0]['in_shipment']);
        $this->assertSame(12, $data['items'][0]['shipped']);
        $this->assertSame(88, $data['items'][0]['remaining']);
        $this->assertSame(28, $data['totals']['in_shipment']);
        $this->assertSame(['LWSCN26247 · Customs'], $data['items'][0]['in_shipment_refs']);
    }

    public function test_shared_blade_shows_the_column_only_for_the_admin_widget(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->ship(ShipmentStatus::CUSTOMS, 28);
        $label = __('widgets.fulfillment.in_shipment');

        Livewire::test(ShipmentFulfillmentWidget::class, ['record' => $this->pi->fresh()])
            ->assertSee($label)
            ->assertSee('28');

        // O blade é compartilhado com o portal, o PO e o portal do fornecedor:
        // sem a flag, a coluna não aparece — e a renderização não quebra.
        $portal = new PortalShipmentFulfillmentWidget;
        $portal->record = $this->pi->fresh();
        $this->assertArrayNotHasKey('showInShipment', (fn () => $this->getViewData())->call($portal));

        Livewire::test(PortalShipmentFulfillmentWidget::class, ['record' => $this->pi->fresh()])
            ->assertDontSee($label);
    }

    public function test_items_list_shows_the_in_shipment_column(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->ship(ShipmentStatus::BOOKED, 40);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->pi->fresh(),
            'pageClass' => ViewProformaInvoice::class,
        ])
            ->assertTableColumnExists('quantity_in_shipment')
            ->assertTableColumnStateSet('quantity_in_shipment', 40, record: $this->piItem)
            ->assertTableColumnStateSet('quantity_shipped', 0, record: $this->piItem)
            ->assertTableColumnStateSet('quantity_remaining', 100, record: $this->piItem);
    }
}
