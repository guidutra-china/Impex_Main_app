<?php

namespace Tests\Feature\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Support\QuotationItemUnitBackfill;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O backfill da migração roda em MySQL (produção) e em SQLite (esta suíte);
 * aqui provamos a regra de precedência em cima de linhas já gravadas com o
 * default 'pcs' — o estado exato em que a migração encontra os dados.
 */
class QuotationItemUnitBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_sq_unit_beats_inquiry_unit_which_beats_the_default(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::factory()->create(['inquiry_id' => $inquiry->id, 'company_id' => $client->id]);

        $withSq = Product::factory()->create();
        $withInquiryOnly = Product::factory()->create();
        $withNothing = Product::factory()->create();

        InquiryItem::create(['inquiry_id' => $inquiry->id, 'product_id' => $withSq->id, 'quantity' => 1, 'unit' => 'M2']);
        InquiryItem::create(['inquiry_id' => $inquiry->id, 'product_id' => $withInquiryOnly->id, 'quantity' => 1, 'unit' => 'SET']);

        $sq = SupplierQuotation::create([
            'reference' => 'SQ-BF-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => 'received',
        ]);
        $sqItem = SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $withSq->id,
            'quantity' => 1,
            'unit' => 'SQM',
            'unit_cost' => 1000,
        ]);

        $rows = collect([
            [$withSq->id, $sqItem->id],
            [$withInquiryOnly->id, null],
            [$withNothing->id, null],
        ])->map(fn ($r) => QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $r[0],
            'supplier_quotation_item_id' => $r[1],
            'quantity' => 1,
            'unit_cost' => 0,
            'unit_price' => 0,
        ]));

        // Estado pré-migração: tudo no default.
        QuotationItem::query()->update(['unit' => 'pcs']);

        QuotationItemUnitBackfill::run();

        $this->assertSame('SQM', $rows[0]->fresh()->unit);
        $this->assertSame('SET', $rows[1]->fresh()->unit);
        $this->assertSame('pcs', $rows[2]->fresh()->unit);

        // Idempotente.
        QuotationItemUnitBackfill::run();
        $this->assertSame(['SQM', 'SET', 'pcs'], $rows->map(fn ($r) => $r->fresh()->unit)->all());
    }
}
