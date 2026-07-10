<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\ResolveInquiryDraft;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveInquiryDraftTest extends TestCase
{
    use RefreshDatabase;

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
}
