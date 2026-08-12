<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Once a Proforma Invoice is created from an inquiry, its reference must be
 * visible on the Inquiries list (badge column) and on the View page.
 */
class InquiryRelatedPiColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    public function test_inquiries_list_shows_related_pi_reference(): void
    {
        $company = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $company->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $company->id,
        ]);

        Livewire::test(ListInquiries::class)
            ->assertSee($pi->reference);
    }

    public function test_inquiry_view_shows_related_pi_reference(): void
    {
        $company = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $company->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $company->id,
        ]);

        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->assertSee($pi->reference);
    }
}
