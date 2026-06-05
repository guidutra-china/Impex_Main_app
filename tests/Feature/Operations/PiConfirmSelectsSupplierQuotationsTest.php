<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PiConfirmSelectsSupplierQuotationsTest extends TestCase
{
    use RefreshDatabase;

    private function confirmPi(ProformaInvoice $pi): void
    {
        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);
    }

    private function piWithLinkedSq(SupplierQuotationStatus $sqStatus): array
    {
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::SENT->value]);
        $sq = SupplierQuotation::factory()->create(['status' => $sqStatus->value]);
        $pi->supplierQuotations()->attach($sq->id);

        return [$pi, $sq];
    }

    public function test_confirming_pi_selects_linked_received_sq(): void
    {
        [$pi, $sq] = $this->piWithLinkedSq(SupplierQuotationStatus::RECEIVED);

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
    }

    public function test_confirming_pi_selects_linked_under_analysis_sq(): void
    {
        [$pi, $sq] = $this->piWithLinkedSq(SupplierQuotationStatus::UNDER_ANALYSIS);

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_select_a_requested_sq(): void
    {
        [$pi, $sq] = $this->piWithLinkedSq(SupplierQuotationStatus::REQUESTED);

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::REQUESTED->value, $sq->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_select_a_rejected_sq(): void
    {
        [$pi, $sq] = $this->piWithLinkedSq(SupplierQuotationStatus::REJECTED);

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::REJECTED->value, $sq->fresh()->status->value);
    }

    public function test_confirming_pi_selects_all_linked_sqs(): void
    {
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::SENT->value]);
        $sq1 = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::RECEIVED->value]);
        $sq2 = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::UNDER_ANALYSIS->value]);
        $pi->supplierQuotations()->attach([$sq1->id, $sq2->id]);

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq1->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq2->fresh()->status->value);
    }

    public function test_unlinked_sq_is_untouched(): void
    {
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::SENT->value]);
        $other = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::RECEIVED->value]);
        // NOT attached to $pi.

        $this->confirmPi($pi);

        $this->assertSame(SupplierQuotationStatus::RECEIVED->value, $other->fresh()->status->value);
    }

    public function test_selects_linked_sqs_via_table_confirm_path(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        [$pi, $sq] = $this->piWithLinkedSq(SupplierQuotationStatus::RECEIVED);

        Livewire::test(ListProformaInvoices::class)
            ->callTableAction('transition_to_confirmed', $pi);

        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
    }

    public function test_confirming_pi_fires_inquiry_won_and_sq_selected_together(): void
    {
        // Both PI-CONFIRMED auto-advance rules must fire on the same confirm.
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTED->value]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => ProformaInvoiceStatus::SENT->value,
        ]);
        $sq = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::RECEIVED->value]);
        $pi->supplierQuotations()->attach($sq->id);

        $this->confirmPi($pi);

        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
    }
}
