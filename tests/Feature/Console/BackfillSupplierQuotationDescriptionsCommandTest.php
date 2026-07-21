<?php

namespace Tests\Feature\Console;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSupplierQuotationDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::factory()->create();
    }

    private function makeSq(string $reference, ?Inquiry $inquiry, ?string $description = null): SupplierQuotation
    {
        return SupplierQuotation::create([
            'reference' => $reference,
            'inquiry_id' => $inquiry?->id,
            'description' => $description,
            'company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::REQUESTED,
        ]);
    }

    public function test_copies_inquiry_description_into_blank_supplier_quotations(): void
    {
        $inquiry = Inquiry::factory()->create(['description' => 'Peças de trator John Deere']);
        $sq = $this->makeSq('SQ-BLANK', $inquiry);

        $this->artisan('supplier-quotations:backfill-descriptions')
            ->assertSuccessful();

        $this->assertSame('Peças de trator John Deere', $sq->fresh()->description);
    }

    public function test_does_not_overwrite_existing_description_and_skips_blank_inquiries(): void
    {
        $inquiryWithDescription = Inquiry::factory()->create(['description' => 'Nova descrição']);
        $alreadyFilled = $this->makeSq('SQ-FILLED', $inquiryWithDescription, 'Descrição manual');

        $inquiryWithout = Inquiry::factory()->create(['description' => null]);
        $blankSource = $this->makeSq('SQ-NODESC', $inquiryWithout);

        $this->artisan('supplier-quotations:backfill-descriptions')
            ->assertSuccessful();

        $this->assertSame('Descrição manual', $alreadyFilled->fresh()->description);
        $this->assertNull($blankSource->fresh()->description);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $inquiry = Inquiry::factory()->create(['description' => 'Peças de trator']);
        $sq = $this->makeSq('SQ-DRY', $inquiry);

        $this->artisan('supplier-quotations:backfill-descriptions', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($sq->fresh()->description);
    }
}
