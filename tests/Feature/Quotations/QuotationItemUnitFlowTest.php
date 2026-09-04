<?php

namespace Tests\Feature\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\ProformaInvoices\Actions\SyncProformaInvoiceFromQuotationAction;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Depois da Quotation, a unidade tem que seguir para o PDF ao cliente e para
 * a PI gerada dela — os dois lugares que imprimiam "pcs" fixo.
 */
class QuotationItemUnitFlowTest extends TestCase
{
    use RefreshDatabase;

    private function quotationWithItem(string $unit): Quotation
    {
        $quotation = Quotation::factory()->create();

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 50,
            'unit' => $unit,
            'unit_cost' => 50000,
            'unit_price' => 80000,
            'sort_order' => 0,
        ]);

        return $quotation->fresh('items');
    }

    public function test_pdf_prints_the_item_unit(): void
    {
        $quotation = $this->quotationWithItem('SQM');

        $data = (new QuotationPdfTemplate($quotation, 'en'))->getData();

        $this->assertSame('SQM', $data['items'][0]['unit']);
    }

    public function test_pi_generated_from_the_quotation_keeps_the_unit_on_create_and_on_regenerate(): void
    {
        $quotation = $this->quotationWithItem('SQM');
        $action = app(SyncProformaInvoiceFromQuotationAction::class);

        ['pi' => $pi] = $action->execute($quotation);
        $this->assertSame('SQM', $pi->items()->first()->unit);

        $quotation->items()->first()->update(['unit' => 'ROLL']);
        $action->execute($quotation->fresh('items'));

        $this->assertSame('ROLL', $pi->fresh()->items()->first()->unit);
    }
}
