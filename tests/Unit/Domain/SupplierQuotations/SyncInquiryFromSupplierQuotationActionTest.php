<?php

namespace Tests\Unit\Domain\SupplierQuotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Actions\SyncInquiryFromSupplierQuotationAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncInquiryFromSupplierQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeAction(): SyncInquiryFromSupplierQuotationAction
    {
        return app(SyncInquiryFromSupplierQuotationAction::class);
    }

    private function makeSq(?Inquiry $inquiry = null): SupplierQuotation
    {
        return SupplierQuotation::create([
            'inquiry_id' => $inquiry?->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::RECEIVED,
        ]);
    }

    private function addSqItem(SupplierQuotation $sq, ?int $productId, array $attributes = []): SupplierQuotationItem
    {
        return SupplierQuotationItem::create(array_merge([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $productId,
            'description' => 'Item do fornecedor',
            'quantity' => 50,
            'unit' => 'pcs',
            'unit_cost' => 120000,
        ], $attributes));
    }

    public function test_creates_inquiry_and_links_loose_supplier_quotation(): void
    {
        $client = Company::factory()->create();
        $sq = $this->makeSq();
        $product = Product::factory()->create();
        $this->addSqItem($sq, $product->id);

        $inquiry = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'CNY',
        );

        $this->assertSame($client->id, $inquiry->company_id);
        $this->assertSame('CNY', $inquiry->currency_code);
        $this->assertNotEmpty($inquiry->reference);
        $this->assertSame($inquiry->id, $sq->fresh()->inquiry_id);
        $this->assertSame(1, $inquiry->items()->count());
    }

    public function test_reuses_inquiry_when_client_matches(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, Product::factory()->create()->id);

        $result = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame($inquiry->id, $result->id);
        $this->assertSame(1, Inquiry::count());
    }

    public function test_creates_new_inquiry_when_client_differs_and_keeps_original_link(): void
    {
        $originalClient = Company::factory()->create();
        $otherClient = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $originalClient->id]);
        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, Product::factory()->create()->id);

        $result = $this->makeAction()->execute(
            sq: $sq,
            companyId: $otherClient->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertNotSame($inquiry->id, $result->id);
        $this->assertSame($otherClient->id, $result->company_id);
        // O vínculo original da SQ não é roubado.
        $this->assertSame($inquiry->id, $sq->fresh()->inquiry_id);
        $this->assertSame(0, $inquiry->fresh()->items()->count());
        $this->assertSame(1, $result->items()->count());
    }

    public function test_reruns_for_a_different_client_reuse_the_inquiry_they_created(): void
    {
        $originalClient = Company::factory()->create();
        $otherClient = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $originalClient->id]);
        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, Product::factory()->create()->id);

        $action = $this->makeAction();

        $first = $action->execute(sq: $sq, companyId: $otherClient->id, contactId: null, currencyCode: 'USD');
        $second = $action->execute(sq: $sq, companyId: $otherClient->id, contactId: null, currencyCode: 'USD');
        $third = $action->execute(sq: $sq, companyId: $otherClient->id, contactId: null, currencyCode: 'USD');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        // A inquiry original do cliente A + a única criada para o cliente B.
        $this->assertSame(2, Inquiry::count());
        $this->assertSame(1, $third->items()->count());
        $this->assertSame(1, $third->stateTransitions()->count());
    }

    public function test_adds_only_missing_items_and_preserves_existing_quantity(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $known = Product::factory()->create();
        $extra = Product::factory()->create();

        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $known->id,
            'quantity' => 10,
            'sort_order' => 0,
        ]);

        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, $known->id, ['quantity' => 999]);
        $this->addSqItem($sq, $extra->id, ['quantity' => 30, 'description' => 'Opção extra do fornecedor']);

        $result = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame(2, $result->items()->count());
        $this->assertSame(10, $result->items()->where('product_id', $known->id)->value('quantity'));
        $extraItem = $result->items()->where('product_id', $extra->id)->first();
        $this->assertSame(30, $extraItem->quantity);
        $this->assertSame('Opção extra do fornecedor', $extraItem->description);
        $this->assertSame(1, $extraItem->sort_order);
    }

    public function test_creates_draft_product_for_item_without_product_and_backfills_sq_item(): void
    {
        $client = Company::factory()->create();
        $sq = $this->makeSq();
        $sqItem = $this->addSqItem($sq, null, ['description' => 'Kettlebell 16kg']);

        $inquiry = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $item = $inquiry->items()->first();
        $product = Product::find($item->product_id);
        $this->assertSame('Kettlebell 16kg', $product->name);
        $this->assertSame(
            1,
            $product->suppliers()->where('companies.id', $sq->company_id)->count(),
        );
        // Backfill: reexecutar não cria outro produto.
        $this->assertSame($product->id, $sqItem->fresh()->product_id);
    }

    public function test_elects_lowest_unit_cost_item_when_two_sq_items_share_a_product(): void
    {
        $client = Company::factory()->create();
        $sq = $this->makeSq();
        $product = Product::factory()->create();

        // A linha mais cara vem primeiro na ordem — a eleição não pode seguir a
        // ordem das linhas, tem que seguir o menor unit_cost.
        $this->addSqItem($sq, $product->id, [
            'unit_cost' => 150000,
            'quantity' => 20,
            'description' => 'Tier caro (MOQ baixo)',
            'sort_order' => 0,
        ]);
        $this->addSqItem($sq, $product->id, [
            'unit_cost' => 90000,
            'quantity' => 500,
            'description' => 'Tier barato (MOQ alto)',
            'sort_order' => 1,
        ]);

        $inquiry = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame(1, $inquiry->items()->count());
        $item = $inquiry->items()->first();
        $this->assertSame(500, $item->quantity);
        $this->assertSame('Tier barato (MOQ alto)', $item->description);
    }

    public function test_dedups_null_product_items_by_description_before_minting_drafts(): void
    {
        $client = Company::factory()->create();
        $sq = $this->makeSq();

        $first = $this->addSqItem($sq, null, [
            'description' => '  Kettlebell 16kg  ',
            'unit_cost' => 100000,
            'quantity' => 10,
            'sort_order' => 0,
        ]);
        $second = $this->addSqItem($sq, null, [
            'description' => 'kettlebell 16kg',
            'unit_cost' => 80000,
            'quantity' => 40,
            'sort_order' => 1,
        ]);

        $inquiry = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame(1, Product::count());
        $this->assertSame($first->fresh()->product_id, $second->fresh()->product_id);
        $this->assertSame(1, $inquiry->items()->count());
        // Também respeita a eleição por menor custo entre as duas linhas casadas.
        $this->assertSame(40, $inquiry->items()->first()->quantity);
    }

    public function test_keeps_link_to_soft_deleted_product_without_minting_a_duplicate(): void
    {
        $client = Company::factory()->create();
        $sq = $this->makeSq();
        $product = Product::factory()->create();
        $product->delete();
        $sqItem = $this->addSqItem($sq, $product->id, ['description' => 'Produto removido']);

        $inquiry = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame($product->id, $sqItem->fresh()->product_id);
        $this->assertSame(1, Product::withTrashed()->count());
        $item = $inquiry->items()->first();
        $this->assertSame($product->id, $item->product_id);
    }

    public function test_advances_inquiry_from_received_to_quoting(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'status' => InquiryStatus::RECEIVED,
        ]);
        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, Product::factory()->create()->id);

        $result = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame(InquiryStatus::QUOTING, $result->status);
    }

    public function test_does_not_touch_inquiry_status_when_not_received(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'status' => InquiryStatus::QUOTED,
        ]);
        $sq = $this->makeSq($inquiry);
        $this->addSqItem($sq, Product::factory()->create()->id);

        $result = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
        );

        $this->assertSame(InquiryStatus::QUOTED, $result->status);
    }
}
