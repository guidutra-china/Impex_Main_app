<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkDeepFitnessAccessoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_accessories_via_sq_product_pool_and_ignores_global_name_twins(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $plate = Product::factory()->create(['name' => 'Weight plate 5kg', 'sku' => 'WEP-00002']);
        $dumbbell = Product::factory()->create(['name' => 'Dumbbell 3kg', 'sku' => 'DBE-00011']);

        // Homônimo fora do pool das SQs — não pode ser usado no match.
        Product::factory()->create(['name' => 'Weight plate 5kg', 'sku' => 'OTHER-001']);

        // Duplicata criada pelo import de uma SQ REJEITADA — também fora do pool.
        $rejectedTwin = Product::factory()->create(['name' => 'Dumbbell 3kg', 'sku' => 'DBE-00003R']);

        $sq = SupplierQuotation::create([
            'reference' => 'SQ-TEST-085',
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::RECEIVED,
        ]);
        $rejectedSq = SupplierQuotation::create([
            'reference' => 'SQ-TEST-079',
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::REJECTED,
        ]);

        foreach ([[$sq, $plate], [$sq, $dumbbell], [$rejectedSq, $rejectedTwin]] as [$quotation, $product]) {
            SupplierQuotationItem::create([
                'supplier_quotation_id' => $quotation->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'unit_cost' => 1000,
                'quantity' => 1,
                'unit' => 'pcs',
            ]);
        }

        $plateItem = InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'description' => 'DPF-WP-5',
            'quantity' => 300, 'unit' => 'pcs', 'sort_order' => 1,
        ]);
        $dumbbellItem = InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'description' => 'DPF-HEX-DB- 3',
            'quantity' => 60, 'unit' => 'pcs', 'sort_order' => 2,
        ]);
        $unknown = InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'description' => 'DPF-SOMETHING-ELSE',
            'quantity' => 1, 'unit' => 'pcs', 'sort_order' => 3,
        ]);

        $this->artisan('inquiries:link-deep-fitness-accessories', [
            'inquiry' => $inquiry->reference,
        ])->assertSuccessful();

        $this->assertNull($plateItem->fresh()->product_id, 'dry-run must not persist');

        $this->artisan('inquiries:link-deep-fitness-accessories', [
            'inquiry' => $inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($plate->id, $plateItem->fresh()->product_id);
        $this->assertSame($dumbbell->id, $dumbbellItem->fresh()->product_id);
        $this->assertNull($unknown->fresh()->product_id);
    }
}
