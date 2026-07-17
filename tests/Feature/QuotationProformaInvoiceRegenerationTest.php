<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\ProformaInvoices\Actions\SyncProformaInvoiceFromQuotationAction;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QuotationProformaInvoiceRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuotationWithItems(int $itemCount = 2, array $quotationAttributes = []): Quotation
    {
        $quotation = Quotation::factory()->create($quotationAttributes);

        for ($i = 0; $i < $itemCount; $i++) {
            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'product_id' => Product::factory()->create()->id,
                'quantity' => 100,
                'unit_cost' => 50000,
                'unit_price' => 80000,
                'sort_order' => $i,
            ]);
        }

        return $quotation->fresh('items');
    }

    private function action(): SyncProformaInvoiceFromQuotationAction
    {
        return app(SyncProformaInvoiceFromQuotationAction::class);
    }

    public function test_first_execution_creates_a_pi_linked_to_the_quotation(): void
    {
        $quotation = $this->makeQuotationWithItems(2);

        ['pi' => $pi, 'created' => $created] = $this->action()->execute($quotation);

        $this->assertTrue($created);
        $this->assertSame(2, $pi->items()->count());
        $this->assertTrue($pi->quotations()->whereKey($quotation->id)->exists());
        $this->assertEqualsCanonicalizing(
            $quotation->items->pluck('id')->all(),
            $pi->items()->pluck('quotation_item_id')->all(),
        );
    }

    public function test_second_execution_updates_the_existing_pi_instead_of_creating_a_new_one(): void
    {
        $quotation = $this->makeQuotationWithItems(2);

        ['pi' => $pi] = $this->action()->execute($quotation);

        $quotation->items->first()->update(['quantity' => 250, 'unit_price' => 90000]);

        ['pi' => $piAgain, 'created' => $created] = $this->action()->execute($quotation->fresh('items'));

        $this->assertFalse($created);
        $this->assertSame($pi->id, $piAgain->id);
        $this->assertSame(1, ProformaInvoice::count());
        $this->assertSame(2, $piAgain->items()->count());

        $updatedItem = $piAgain->items()
            ->where('quotation_item_id', $quotation->items->first()->id)
            ->firstOrFail();
        $this->assertSame(250, $updatedItem->quantity);
        $this->assertSame(90000, $updatedItem->unit_price);
    }

    public function test_regeneration_adds_new_quotation_items_and_keeps_manual_pi_items(): void
    {
        $quotation = $this->makeQuotationWithItems(1);

        ['pi' => $pi] = $this->action()->execute($quotation);

        $manualItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => Product::factory()->create()->id,
            'quotation_item_id' => null,
            'description' => 'Manual item',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => 12345,
            'unit_cost' => 10000,
            'sort_order' => 99,
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 5,
            'unit_cost' => 20000,
            'unit_price' => 30000,
            'sort_order' => 1,
        ]);

        ['pi' => $piAgain] = $this->action()->execute($quotation->fresh('items'));

        $this->assertSame(3, $piAgain->items()->count());
        $this->assertSame(12345, $manualItem->fresh()->unit_price, 'Manual PI item must not be touched.');
    }

    public function test_deleting_a_quotation_item_removes_the_linked_draft_pi_item(): void
    {
        $quotation = $this->makeQuotationWithItems(2);

        ['pi' => $pi] = $this->action()->execute($quotation);

        $removed = $quotation->items->first();
        $removed->delete();

        $this->assertSame(1, $pi->items()->count());

        ['pi' => $piAgain] = $this->action()->execute($quotation->fresh('items'));

        $this->assertSame(1, $piAgain->items()->count(), 'Removed quotation item must not resurface or duplicate.');
    }

    public function test_regeneration_is_blocked_when_pi_is_beyond_draft(): void
    {
        $quotation = $this->makeQuotationWithItems(1);

        ['pi' => $pi] = $this->action()->execute($quotation);
        $pi->update(['status' => 'sent']);

        $this->expectException(RuntimeException::class);

        $this->action()->execute($quotation->fresh('items'));
    }

    public function test_regeneration_recalculates_pending_separate_commission_cost(): void
    {
        $quotation = $this->makeQuotationWithItems(1, [
            'commission_type' => 'separate',
            'commission_rate' => 10,
        ]);

        ['pi' => $pi] = $this->action()->execute($quotation);

        $cost = $pi->additionalCosts()
            ->where('cost_type', AdditionalCostType::COMMISSION)
            ->firstOrFail();

        // Base: 100 pcs × 50000 (SEPARATE uses cost as PI unit_price) × 10%.
        $this->assertSame((int) round(100 * 50000 * 0.10), $cost->amount);

        $quotation->items->first()->update(['quantity' => 200]);

        $this->action()->execute($quotation->fresh('items'));

        $this->assertSame((int) round(200 * 50000 * 0.10), $cost->fresh()->amount);
        $this->assertSame(
            1,
            $pi->additionalCosts()->where('cost_type', AdditionalCostType::COMMISSION)->count(),
            'Regeneration must not duplicate the commission cost.',
        );
    }

    public function test_updated_quotation_header_fields_propagate_to_the_pi(): void
    {
        $quotation = $this->makeQuotationWithItems(1, ['validity_days' => 30]);

        ['pi' => $pi] = $this->action()->execute($quotation);

        $quotation->update(['validity_days' => 60]);

        $this->action()->execute($quotation->fresh('items'));

        $this->assertSame(60, $pi->fresh()->validity_days);
    }
}
