<?php

namespace Tests\Feature\Inquiries;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CreatePiModalDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_create_pi_modal_preselects_latest_quotation_and_valid_supplier_quotations(): void
    {
        $inquiry = Inquiry::factory()->create();

        Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::SENT->value,
            'version' => 1,
        ]);
        $v2 = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::DRAFT->value,
            'version' => 2,
        ]);
        Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::CANCELLED->value,
            'version' => 3,
        ]);

        $validSq = SupplierQuotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => SupplierQuotationStatus::RECEIVED->value,
        ]);
        SupplierQuotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => SupplierQuotationStatus::REJECTED->value,
        ]);
        SupplierQuotation::factory()->create([
            'status' => SupplierQuotationStatus::RECEIVED->value,
        ]);

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->mountAction('createProformaInvoice')
            ->assertActionDataSet([
                'quotation_ids' => [$v2->id],
                'supplier_quotation_ids' => [$validSq->id],
            ]);
    }

    public function test_create_pi_modal_defaults_are_empty_when_inquiry_has_no_quotations(): void
    {
        $inquiry = Inquiry::factory()->create();

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->mountAction('createProformaInvoice')
            ->assertActionDataSet([
                'quotation_ids' => [],
                'supplier_quotation_ids' => [],
            ]);
    }
}
