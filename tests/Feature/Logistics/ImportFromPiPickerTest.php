<?php

namespace Tests\Feature\Logistics;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O modal "Import from PI" precisa mostrar o preço unitário de cada linha:
 * o mesmo produto pode aparecer duas vezes na PI com desconto ou modificação,
 * e sem o valor as duas opções ficam visualmente idênticas.
 */
class ImportFromPiPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_labels_carry_the_unit_price_of_each_line(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $client = Company::factory()->create();
        $inquiry = Inquiry::create([
            'reference' => 'INQ-PICK-1',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PICK-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $po = PurchaseOrder::factory()->create(['proforma_invoice_id' => $pi->id]);
        $product = Product::factory()->create(['name' => 'Idler Pulley', 'model_number' => 'MOD-9']);

        // O MESMO produto duas vezes: preço cheio e preço com desconto.
        foreach ([['full', 125000], ['discounted', 99900]] as $i => [$tag, $unitPrice]) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => 'Idler Pulley',
                'quantity' => 10,
                'unit_price' => $unitPrice,
                'unit' => 'pcs',
                'sort_order' => $i,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'unit_cost' => 50000,
                'sort_order' => $i + 1,
            ]);
        }

        $shipment = Shipment::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        // O modal monta e roda o closure das opções sem erro…
        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])
            ->mountAction(\Filament\Actions\Testing\TestAction::make('import_from_pi')->table())
            ->assertActionMounted(\Filament\Actions\Testing\TestAction::make('import_from_pi')->table());

        // …e cada rótulo carrega o preço da SUA linha, distinguindo as duas.
        $items = ProformaInvoiceItem::where('proforma_invoice_id', $pi->id)
            ->orderBy('sort_order')
            ->get();

        $label = new \ReflectionMethod(ItemsRelationManager::class, 'piItemPickerLabel');

        $full = $label->invoke(null, $items[0], 10, 'USD');
        $discounted = $label->invoke(null, $items[1], 10, 'USD');

        $this->assertStringContainsString('USD 12.5000', $full);
        $this->assertStringContainsString('USD 9.9900', $discounted);

        // O resto do rótulo continua igual ao que já existia.
        $this->assertStringContainsString('MOD-9 — Idler Pulley', $full);
        $this->assertStringContainsString('Qty: 10', $full);
        $this->assertStringContainsString('Remaining: 10', $full);
    }

    public function test_only_remaining_hides_items_already_fully_shipped(): void
    {
        [$pi, $items, $shipment] = $this->makePiWithOneFullyShippedItem();

        $options = new \ReflectionMethod(ItemsRelationManager::class, 'piItemPickerOptions');

        // Marcado (padrão): o item totalmente embarcado sai da lista.
        $onlyRemaining = $options->invoke(null, $pi->id, true);
        $this->assertArrayHasKey($items['open']->id, $onlyRemaining);
        $this->assertArrayNotHasKey($items['shipped']->id, $onlyRemaining);

        // Desmarcado: tudo aparece — é o caso de reembarcar a quantidade cheia.
        $all = $options->invoke(null, $pi->id, false);
        $this->assertArrayHasKey($items['open']->id, $all);
        $this->assertArrayHasKey($items['shipped']->id, $all);
        $this->assertStringContainsString('Remaining: 0', $all[$items['shipped']->id]);
    }

    public function test_partially_shipped_item_stays_in_the_list(): void
    {
        [$pi, $items] = $this->makePiWithOneFullyShippedItem(partialQty: 4);

        $options = (new \ReflectionMethod(ItemsRelationManager::class, 'piItemPickerOptions'))
            ->invoke(null, $pi->id, true);

        $this->assertArrayHasKey($items['partial']->id, $options);
        $this->assertStringContainsString('Remaining: 6', $options[$items['partial']->id]);
    }

    public function test_pi_list_only_shows_invoices_with_something_left_to_ship(): void
    {
        [$open, $client] = $this->makePi('PI-OPEN', shipQty: 0);
        [$fullyShipped] = $this->makePi('PI-DONE', shipQty: 10, client: $client);
        [$partial] = $this->makePi('PI-PART', shipQty: 4, client: $client);
        // PI sem PO vinculada: nada nela é importável, mesmo com saldo.
        [$noPo] = $this->makePi('PI-NOPO', shipQty: 0, client: $client, withPo: false);

        $options = new \ReflectionMethod(ItemsRelationManager::class, 'piPickerOptions');

        $onlyRemaining = $options->invoke(null, $client->id, true);

        $this->assertArrayHasKey($open->id, $onlyRemaining);
        $this->assertArrayHasKey($partial->id, $onlyRemaining, 'PI parcialmente embarcada ainda tem saldo');
        $this->assertArrayNotHasKey($fullyShipped->id, $onlyRemaining);
        $this->assertArrayNotHasKey($noPo->id, $onlyRemaining, 'sem PO vinculada não há o que importar');

        // Desmarcado, a totalmente embarcada volta (caso de reembarque), mas a
        // sem PO continua fora.
        $all = $options->invoke(null, $client->id, false);

        $this->assertArrayHasKey($fullyShipped->id, $all);
        $this->assertArrayNotHasKey($noPo->id, $all);
    }

    public function test_pi_list_ignores_draft_and_cancelled_and_other_clients(): void
    {
        [$valid, $client] = $this->makePi('PI-VALID', shipQty: 0);
        [$draft] = $this->makePi('PI-DRAFT', shipQty: 0, client: $client, status: 'draft');
        [$cancelled] = $this->makePi('PI-CANC', shipQty: 0, client: $client, status: 'cancelled');
        [$otherClient] = $this->makePi('PI-OTHER', shipQty: 0);

        $options = (new \ReflectionMethod(ItemsRelationManager::class, 'piPickerOptions'))
            ->invoke(null, $client->id, true);

        $this->assertArrayHasKey($valid->id, $options);
        $this->assertArrayNotHasKey($draft->id, $options);
        $this->assertArrayNotHasKey($cancelled->id, $options);
        $this->assertArrayNotHasKey($otherClient->id, $options);
    }

    public function test_label_shows_the_remaining_quantity_it_receives(): void
    {
        $item = new ProformaInvoiceItem([
            'description' => 'Item solto',
            'quantity' => 30,
            'unit_price' => 250000,
        ]);

        $label = (new \ReflectionMethod(ItemsRelationManager::class, 'piItemPickerLabel'))
            ->invoke(null, $item, 12, 'BRL');

        $this->assertStringContainsString('Qty: 30', $label);
        $this->assertStringContainsString('Remaining: 12', $label);
        $this->assertStringContainsString('BRL 25.0000', $label);
    }

    /**
     * PI com um item aberto e um totalmente embarcado (e, opcionalmente, um
     * parcialmente embarcado).
     *
     * @return array{0: ProformaInvoice, 1: array<string, ProformaInvoiceItem>, 2: Shipment}
     */
    private function makePiWithOneFullyShippedItem(?int $partialQty = null): array
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::create([
            'reference' => 'INQ-REM-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-REM-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $po = PurchaseOrder::factory()->create(['proforma_invoice_id' => $pi->id]);
        $product = Product::factory()->create(['name' => 'Pulley', 'model_number' => 'MOD-R']);

        $make = function (string $key, int $sort) use ($pi, $po, $product) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => 'Pulley '.$key,
                'quantity' => 10,
                'unit_price' => 100000,
                'unit' => 'pcs',
                'sort_order' => $sort,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'unit_cost' => 50000,
                'sort_order' => $sort + 1,
            ]);

            return $piItem;
        };

        $items = [
            'open' => $make('open', 0),
            'shipped' => $make('shipped', 1),
        ];

        if ($partialQty !== null) {
            $items['partial'] = $make('partial', 2);
        }

        // Embarque que conta como embarcado consome a quantidade.
        $shipped = Shipment::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'in_transit',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipped->id,
            'proforma_invoice_item_id' => $items['shipped']->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        if ($partialQty !== null) {
            ShipmentItem::create([
                'shipment_id' => $shipped->id,
                'proforma_invoice_item_id' => $items['partial']->id,
                'quantity' => $partialQty,
                'unit' => 'pcs',
                'sort_order' => 1,
            ]);
        }

        return [$pi, $items, $shipped];
    }

    /**
     * PI com um item (opcionalmente com PO vinculada) e, quando shipQty > 0,
     * um embarque que já consumiu essa quantidade.
     *
     * @return array{0: ProformaInvoice, 1: Company}
     */
    private function makePi(
        string $prefix,
        int $shipQty,
        ?Company $client = null,
        bool $withPo = true,
        string $status = 'confirmed',
    ): array {
        $client ??= Company::factory()->create();

        $inquiry = Inquiry::create([
            'reference' => $prefix.'-INQ-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => $prefix.'-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => $status,
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory()->create()->id,
            'description' => 'Item',
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        if ($withPo) {
            $po = PurchaseOrder::factory()->create(['proforma_invoice_id' => $pi->id]);
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $piItem->product_id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'unit_cost' => 50000,
                'sort_order' => 1,
            ]);
        }

        if ($shipQty > 0) {
            $shipment = Shipment::factory()->create([
                'company_id' => $client->id,
                'currency_code' => 'USD',
                'status' => 'in_transit',
            ]);

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $shipQty,
                'unit' => 'pcs',
                'sort_order' => 0,
            ]);
        }

        return [$pi, $client];
    }
}
