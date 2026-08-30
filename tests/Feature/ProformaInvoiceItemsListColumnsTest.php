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

    public function test_cost_block_is_ordered_cost_rate_converted_price(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'CNY']);
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'quantity' => 10,
            'unit_cost' => 700000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 1,
        ]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertSuccessful();

        $names = array_keys($component->instance()->getTable()->getColumns());
        $block = array_values(array_intersect($names, [
            'unit_cost', 'cost_exchange_rate', 'unit_cost_in_document_currency', 'unit_price',
        ]));

        $this->assertSame(
            ['unit_cost', 'cost_exchange_rate', 'unit_cost_in_document_currency', 'unit_price'],
            $block,
        );
    }

    public function test_column_order_from_code_wins_over_a_stale_session_state(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'CNY']);
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
        ]);

        // Estado gravado na sessão pela ordem ANTIGA (Price antes de Cost), sem a
        // coluna nova. O Filament v4 persiste isso por padrão.
        $stale = collect(['unit_price', 'cost_currency_code', 'cost_exchange_rate', 'unit_cost'])
            ->map(fn (string $name) => [
                'type' => 'column',
                'name' => $name,
                'label' => $name,
                'isHidden' => false,
                'isToggled' => true,
                'isToggleable' => true,
                'isToggledHiddenByDefault' => false,
            ])
            ->all();

        session()->put('tables.'.md5(ItemsRelationManager::class).'_columns', $stale);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertSuccessful();

        $names = array_keys($component->instance()->getTable()->getColumns());
        $block = array_values(array_intersect($names, [
            'unit_cost', 'cost_exchange_rate', 'unit_cost_in_document_currency', 'unit_price',
        ]));

        $this->assertSame(
            ['unit_cost', 'cost_exchange_rate', 'unit_cost_in_document_currency', 'unit_price'],
            $block,
        );

        // A coluna nova não está no estado antigo: precisa aparecer mesmo assim.
        $this->assertFalse(
            $component->instance()->isTableColumnToggledHidden('unit_cost_in_document_currency'),
        );
    }

    public function test_converted_cost_column_renders_with_the_pi_currency_inline(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'CNY']);
        $item = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'quantity' => 10,
            'unit_cost' => 700000, // 70.0000 CNY (minor units, escala 10000)
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 1,
        ]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertCanRenderTableColumn('unit_cost_in_document_currency');

        $column = $component->instance()->getTable()->getColumn('unit_cost_in_document_currency');
        $column->record($item);

        // formatState já aplica o prefixo: a moeda da PI sai colada no valor.
        $this->assertSame('CNY ', $column->getPrefix());
        $this->assertSame('CNY 70.0000', $column->formatState($item->unit_cost_in_document_currency));
    }

    public function test_exchange_rate_column_shows_both_directions_of_the_pair(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'USD']);
        $item = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.148790,
        ]);

        $column = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertSuccessful()->instance()->getTable()->getColumn('cost_exchange_rate')->record($item);

        $this->assertSame(
            '0.148790 / 6.720882',
            $column->formatState($item->cost_exchange_rate),
        );
    }

    public function test_exchange_rate_column_stays_plain_when_cost_is_in_the_pi_currency(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'CNY']);
        $item = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 1,
        ]);

        $column = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertSuccessful()->instance()->getTable()->getColumn('cost_exchange_rate')->record($item);

        $this->assertSame('1.000000', $column->formatState($item->cost_exchange_rate));
    }

    public function test_price_and_total_columns_use_the_pi_currency_not_a_hardcoded_dollar(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'CNY']);
        $item = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory(),
            'quantity' => 60,
            'unit_price' => 165000, // 16.5000 CNY
        ]);

        $table = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])->assertSuccessful()->instance()->getTable();

        $price = $table->getColumn('unit_price')->record($item);
        $total = $table->getColumn('line_total')->record($item);

        $this->assertSame('CNY ', $price->getPrefixLabel()); // TextInputColumn
        $this->assertSame('CNY ', $total->getPrefix());
        $this->assertSame('CNY 990.00', $total->formatState($item->line_total));
    }
}
