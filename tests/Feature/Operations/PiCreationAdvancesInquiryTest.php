<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PiCreationAdvancesInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_pi_from_a_quoting_inquiry_advances_it_to_quoted(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTING->value]);

        // Drive the inquiry header "Create Proforma Invoice" action.
        Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
            ->callAction('createProformaInvoice', data: [
                'quotation_ids' => [],
                'supplier_quotation_ids' => [],
            ]);

        $this->assertSame(InquiryStatus::QUOTED->value, $inquiry->fresh()->status->value);
        $this->assertSame(1, ProformaInvoice::where('inquiry_id', $inquiry->id)->count());
    }
}
