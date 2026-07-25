<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\ProductMatchSuggester;
use App\Domain\AI\Import\ResolveInquiryDraft;
use App\Domain\AI\Import\Support\NameNormalizer;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveInquiryDraftTest extends TestCase
{
    use RefreshDatabase;

    private ?object $lastSuggester = null;

    public function test_matches_existing_client_and_product(): void
    {
        $client = Company::factory()->create(['name' => 'DeepFitness Ltda']);
        $product = Product::factory()->create(['reference_code' => 'DF-100']);

        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => 'DeepFitness', 'currency_code' => 'usd'],
            'itens' => [
                ['part_no' => 'DF-100', 'description' => 'Treadmill', 'quantity' => 3, 'target_price' => 250.5],
                ['description' => 'Unknown thing', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('existente', $preview['cliente']['status']);
        $this->assertSame($client->id, $preview['cliente']['company_id']);
        $this->assertSame('USD', $preview['cabecalho']['currency_code']);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
        $this->assertSame(\App\Domain\Infrastructure\Support\Money::toMinor(250.5), $preview['itens'][0]['target_price_minor']);

        $this->assertSame('novo', $preview['itens'][1]['status']);
        $this->assertNull($preview['itens'][1]['product_id']);
        $this->assertNull($preview['itens'][1]['target_price_minor']);

        $this->assertSame(2, $preview['resumo']['total_itens']);
        $this->assertSame(1, $preview['resumo']['produtos_casados']);
        $this->assertSame('USD '.\App\Domain\Infrastructure\Support\Money::format(\App\Domain\Infrastructure\Support\Money::toMinor(250.5) * 3), $preview['resumo']['total_estimado']);
    }

    public function test_passes_through_description_inferred_flag_and_matched_product_name(): void
    {
        $product = Product::factory()->create(['name' => 'Slam Ball', 'reference_code' => 'SB-6']);

        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => 'Cliente X'],
            'itens' => [
                ['part_no' => 'SB-6', 'description' => 'Bola de peso', 'quantity' => 1, 'descricao_inferida' => true],
                ['description' => 'Sem foto', 'quantity' => 1],
            ],
        ]);

        $this->assertTrue($preview['itens'][0]['description_inferred']);
        $this->assertSame('Slam Ball', $preview['itens'][0]['product_name']);
        $this->assertFalse($preview['itens'][1]['description_inferred']);
        $this->assertNull($preview['itens'][1]['product_name']);
    }

    public function test_matches_product_by_sku_too(): void
    {
        $product = Product::factory()->create(['sku' => 'GYM-00029', 'reference_code' => null, 'model_number' => 'LT012']);

        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => '', 'currency_code' => 'USD'],
            'itens' => [
                ['part_no' => 'GYM-00029', 'description' => 'Lat Pull Down', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
    }

    public function test_unknown_client_is_marked_new(): void
    {
        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => 'Cliente Novo SA'],
            'itens' => [['description' => 'Item', 'quantity' => 2]],
        ]);

        $this->assertSame('novo', $preview['cliente']['status']);
        $this->assertNull($preview['cliente']['company_id']);
        $this->assertSame('Cliente Novo SA', $preview['cliente']['nome']);
        $this->assertSame('USD', $preview['cabecalho']['currency_code']);
    }

    public function test_matches_by_client_scoped_name_alias_and_ai(): void
    {
        $client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        $byName = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $byName->companies()->attach($client->id, ['role' => 'client']);
        $byAlias = Product::factory()->create(['name' => 'Weight plate 10kg']);
        $byAlias->companies()->attach($client->id, ['role' => 'client']);
        $byAi = Product::factory()->create(['name' => 'Vertical Dumbbell Rack']);
        $byAi->companies()->attach($client->id, ['role' => 'client']);

        ProductImportAlias::create([
            'company_id' => $client->id,
            'product_id' => $byAlias->id,
            'alias' => 'Anilha emborrachada 10kg',
            'alias_normalized' => NameNormalizer::normalize('Anilha emborrachada 10kg'),
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $preview = (new ResolveInquiryDraft($this->suggesterReturning([2 => $byAi->id])))->resolve([
            'cliente' => ['nome' => 'DEEP FITNESS', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Hexagonal Dumbbell — 5kg', 'quantity' => 1],
                ['description' => 'Anilha Emborrachada 10kg', 'quantity' => 1],
                ['description' => 'Rack vertical para halteres', 'quantity' => 1],
            ],
        ]);

        $this->assertSame([$byName->id, 'name'], [$preview['itens'][0]['product_id'], $preview['itens'][0]['match_source']]);
        $this->assertSame([$byAlias->id, 'alias'], [$preview['itens'][1]['product_id'], $preview['itens'][1]['match_source']]);
        $this->assertSame([$byAi->id, 'ai'], [$preview['itens'][2]['product_id'], $preview['itens'][2]['match_source']]);
        $this->assertSame(3, $preview['resumo']['produtos_casados']);
    }

    public function test_ai_failure_degrades_silently(): void
    {
        $client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        Product::factory()->create(['name' => 'Something else'])->companies()->attach($client->id, ['role' => 'client']);

        $preview = (new ResolveInquiryDraft($this->suggesterReturning([], throw: true)))->resolve([
            'cliente' => ['nome' => 'DEEP FITNESS', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Coisa desconhecida', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('novo', $preview['itens'][0]['status']);
        $this->assertNull($preview['itens'][0]['product_id']);
        $this->assertNull($preview['itens'][0]['match_source']);
    }

    public function test_suggester_not_called_when_all_items_matched(): void
    {
        $client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        $product = Product::factory()->create(['name' => 'Weight plate 5kg', 'reference_code' => null, 'model_number' => null]);
        $product->companies()->attach($client->id, ['role' => 'client']);

        $preview = (new ResolveInquiryDraft($this->suggesterReturning([], throw: true)))->resolve([
            'cliente' => ['nome' => 'DEEP FITNESS', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Weight plate 5kg', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('name', $preview['itens'][0]['match_source']);
        $this->assertFalse($this->lastSuggester->called);
    }

    public function test_ai_suggestion_outside_client_catalog_is_discarded(): void
    {
        $client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        $foreignProduct = Product::factory()->create(['name' => 'Not in this client catalog']);

        $preview = (new ResolveInquiryDraft($this->suggesterReturning([0 => $foreignProduct->id])))->resolve([
            'cliente' => ['nome' => 'DEEP FITNESS', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Coisa desconhecida', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('novo', $preview['itens'][0]['status']);
        $this->assertNull($preview['itens'][0]['product_id']);
        $this->assertNull($preview['itens'][0]['match_source']);
    }

    /**
     * @param  array<int,int>  $map
     */
    private function suggesterReturning(array $map, bool $throw = false): ProductMatchSuggester
    {
        $suggester = new class($map, $throw) extends ProductMatchSuggester
        {
            public bool $called = false;

            public function __construct(private readonly array $map, private readonly bool $throw)
            {
                parent::__construct();
            }

            public function suggest(array $items, array $catalog): array
            {
                $this->called = true;

                if ($this->throw) {
                    throw new \RuntimeException('API down');
                }

                return array_intersect_key($this->map, $items);
            }
        };

        $this->lastSuggester = $suggester;

        return $suggester;
    }
}
