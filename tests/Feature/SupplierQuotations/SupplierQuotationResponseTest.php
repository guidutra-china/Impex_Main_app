<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\AI\Import\ResolveSupplierQuotationResponse;
use App\Domain\AI\Import\Targets\SupplierQuotationResponseTarget;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Models\StateTransition;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ApplySupplierQuotationResponseAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierQuotationResponseTest extends TestCase
{
    use RefreshDatabase;

    private SupplierQuotation $sq;

    /** @var list<SupplierQuotationItem> */
    private array $items;

    private User $user;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Permission::firstOrCreate(['name' => 'edit-supplier-quotations', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('edit-supplier-quotations');

        $this->sq = SupplierQuotation::factory()->create(['currency_code' => 'USD']);
        $this->items = [];
        foreach ([1, 2, 3] as $i) {
            $this->items[] = SupplierQuotationItem::factory()->create([
                'supplier_quotation_id' => $this->sq->id,
                'description' => "Item {$i}",
                'quantity' => 100,
                'unit_cost' => 0,
                'sort_order' => $i,
            ]);
        }

        $this->file = tempnam(sys_get_temp_dir(), 'resp').'.pdf';
        file_put_contents($this->file, '%PDF-1.4 resposta');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_prompt_lists_our_lines_numbered_with_codes(): void
    {
        $product = Product::factory()->create(['sku' => 'AGR-00169', 'model_number' => '80H-1']);
        $this->items[0]->update(['product_id' => $product->id]);
        $product->companies()->attach($this->sq->company_id, ['role' => 'supplier', 'external_code' => 'SL-80H']);

        $prompt = (new SupplierQuotationResponseTarget($this->sq))->extractionUserPrompt();

        $this->assertStringContainsString('1. SKU: AGR-00169 | Modelo: 80H-1 | Cód. fornecedor: SL-80H | Item 1 | Qtd: 100 pcs', $prompt);
        $this->assertStringContainsString('3. ', $prompt);
        $this->assertStringContainsString($this->sq->reference, $prompt);
    }

    public function test_resolve_maps_document_lines_onto_our_lines(): void
    {
        $preview = app(ResolveSupplierQuotationResponse::class)->resolve($this->sq, [
            'cabecalho' => ['currency_code' => 'usd', 'supplier_reference' => 'SL-2026-88', 'valid_until' => '2026-12-31'],
            'itens' => [
                ['linha' => 1, 'descricao_documento' => '80H-1 chain', 'unit_price' => 1.25, 'moq' => 500, 'lead_time_days' => 30],
                // Total da linha manda (preço por kg): 300 / 200 cotados = 1.50.
                ['linha' => 3, 'descricao_documento' => 'chain 3', 'unit_price' => 9, 'quantity' => 200, 'line_total' => 300],
                ['linha' => 3, 'descricao_documento' => 'duplicada', 'unit_price' => 7],
                ['linha' => 9, 'descricao_documento' => 'fora da lista', 'unit_price' => 2],
            ],
            'extras' => [['descricao' => 'Freight', 'valor' => 80]],
        ]);

        [$l1, $l2, $l3] = $preview['linhas'];
        $this->assertSame([$this->items[0]->id, true, Money::toMinor(1.25), 500, 30], [$l1['sq_item_id'], $l1['matched'], $l1['unit_cost_minor'], $l1['moq'], $l1['lead_time_days']]);
        $this->assertSame([false, null], [$l2['matched'], $l2['unit_cost_minor']]);
        $this->assertSame([Money::toMinor(1.5), 'chain 3'], [$l3['unit_cost_minor'], $l3['source']]);
        $this->assertSame(['duplicada', 'fora da lista', 'Freight'], array_column($preview['nao_casados'], 'descricao'));
        $this->assertSame(['cotados' => 2, 'sem_preco' => 1], $preview['resumo']);
        $this->assertSame(['USD', 'SL-2026-88', '2026-12-31'], [$preview['cabecalho']['currency_code'], $preview['cabecalho']['supplier_reference'], $preview['cabecalho']['valid_until']]);
        $this->assertFalse($preview['currency_mismatch']);
    }

    public function test_resolve_flags_a_different_currency_and_reads_rmb_as_cny(): void
    {
        $preview = app(ResolveSupplierQuotationResponse::class)->resolve($this->sq, [
            'cabecalho' => ['currency_code' => 'RMB'],
            'itens' => [['linha' => 1, 'descricao_documento' => 'x', 'unit_price' => 8]],
        ]);

        $this->assertSame('CNY', $preview['cabecalho']['currency_code']);
        $this->assertTrue($preview['currency_mismatch']);
    }

    public function test_apply_writes_prices_document_and_moves_to_received(): void
    {
        $this->sq->update(['supplier_reference' => 'JA-EXISTE']);

        $result = $this->apply([
            ['sq_item_id' => $this->items[0]->id, 'unit_cost' => '1.25', 'moq' => 500, 'lead_time_days' => 30, 'notes' => 'KMC'],
            ['sq_item_id' => $this->items[1]->id, 'unit_cost' => null],
            ['sq_item_id' => $this->items[2]->id, 'unit_cost' => 1.5],
        ], ['currency_code' => 'USD', 'supplier_reference' => 'SL-2026-88', 'valid_until' => '2026-12-31', 'incoterm' => 'FOB Ningbo']);

        $this->assertSame(['updated' => 2], $result);

        $first = $this->items[0]->fresh();
        $this->assertSame([Money::toMinor(1.25), Money::toMinor(1.25) * 100, 500, 30, 'KMC'], [$first->unit_cost, $first->total_cost, $first->moq, $first->lead_time_days, $first->notes]);
        $this->assertSame(0, $this->items[1]->fresh()->unit_cost);
        $this->assertSame(Money::toMinor(1.5), $this->items[2]->fresh()->unit_cost);

        $sq = $this->sq->fresh();
        $this->assertSame(SupplierQuotationStatus::RECEIVED, $sq->status);
        $this->assertSame('JA-EXISTE', $sq->supplier_reference); // não sobrescreve o que já existia
        $this->assertSame(['2026-12-31', 'FOB Ningbo'], [$sq->valid_until->toDateString(), $sq->incoterm]);
        $this->assertNotNull($sq->received_at);
        $this->assertTrue(StateTransition::where('model_id', $sq->id)->where('to_status', 'received')->exists());

        $document = $sq->documents()->sole();
        $this->assertSame(['supplier_response', 'Proposta Shenlong.pdf', 1], [$document->type, $document->name, $document->version]);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_reimporting_a_revised_response_overwrites_prices_and_versions_the_document(): void
    {
        $this->apply([['sq_item_id' => $this->items[0]->id, 'unit_cost' => 1.25]], ['currency_code' => 'USD']);
        $this->apply([['sq_item_id' => $this->items[0]->id, 'unit_cost' => 1.10]], ['currency_code' => 'USD']);

        $this->assertSame(Money::toMinor(1.10), $this->items[0]->fresh()->unit_cost);
        $this->assertSame(SupplierQuotationStatus::RECEIVED, $this->sq->fresh()->status);
        $document = $this->sq->documents()->sole();
        $this->assertSame(2, $document->version);
        $this->assertSame(1, $document->versions()->count());
    }

    public function test_apply_refuses_a_different_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->apply([['sq_item_id' => $this->items[0]->id, 'unit_cost' => 8]], ['currency_code' => 'CNY']);
        } finally {
            $this->assertSame(0, $this->items[0]->fresh()->unit_cost);
            $this->assertSame(0, $this->sq->documents()->count());
        }
    }

    public function test_apply_refuses_a_line_from_another_quotation(): void
    {
        $foreign = SupplierQuotationItem::factory()->create(['unit_cost' => 0]);

        try {
            $this->apply([
                ['sq_item_id' => $this->items[0]->id, 'unit_cost' => 1],
                ['sq_item_id' => $foreign->id, 'unit_cost' => 9],
            ], []);
            $this->fail('Linha de outra cotação foi aceita.');
        } catch (\InvalidArgumentException) {
            // rollback completo: nada gravado, nenhum arquivo sobrando
            $this->assertSame([0, 0], [$this->items[0]->fresh()->unit_cost, $foreign->fresh()->unit_cost]);
            $this->assertSame(SupplierQuotationStatus::REQUESTED, $this->sq->fresh()->status);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_apply_refuses_a_closed_quotation(): void
    {
        $this->sq->update(['status' => SupplierQuotationStatus::SELECTED]);

        $this->expectException(\InvalidArgumentException::class);
        $this->apply([['sq_item_id' => $this->items[0]->id, 'unit_cost' => 1]], []);
    }

    private function apply(array $linhas, array $cabecalho): array
    {
        return app(ApplySupplierQuotationResponseAction::class)(
            $this->sq, $linhas, $cabecalho, $this->user, $this->file, 'Proposta Shenlong.pdf',
        );
    }
}
