<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PiConfirmApprovesQuotationsTest extends TestCase
{
    use RefreshDatabase;

    private function confirmPi(ProformaInvoice $pi): void
    {
        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);
    }

    private function piWithQuotation(QuotationStatus $qStatus): array
    {
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::SENT->value]);
        $q = Quotation::factory()->create([
            'inquiry_id' => $pi->inquiry_id,
            'status' => $qStatus->value,
        ]);

        return [$pi, $q];
    }

    public function test_confirming_pi_approves_a_sent_quotation(): void
    {
        [$pi, $q] = $this->piWithQuotation(QuotationStatus::SENT);

        $this->confirmPi($pi);

        $this->assertSame(QuotationStatus::APPROVED->value, $q->fresh()->status->value);
    }

    public function test_confirming_pi_approves_a_negotiating_quotation(): void
    {
        [$pi, $q] = $this->piWithQuotation(QuotationStatus::NEGOTIATING);

        $this->confirmPi($pi);

        $this->assertSame(QuotationStatus::APPROVED->value, $q->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_approve_a_draft_quotation(): void
    {
        [$pi, $q] = $this->piWithQuotation(QuotationStatus::DRAFT);

        $this->confirmPi($pi);

        $this->assertSame(QuotationStatus::DRAFT->value, $q->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_touch_a_rejected_quotation(): void
    {
        [$pi, $q] = $this->piWithQuotation(QuotationStatus::REJECTED);

        $this->confirmPi($pi);

        $this->assertSame(QuotationStatus::REJECTED->value, $q->fresh()->status->value);
    }

    public function test_quotation_of_a_different_inquiry_is_untouched(): void
    {
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::SENT->value]);
        $other = Quotation::factory()->create(['status' => QuotationStatus::SENT->value]); // own inquiry

        $this->confirmPi($pi);

        $this->assertSame(QuotationStatus::SENT->value, $other->fresh()->status->value);
    }

    public function test_approves_quotations_via_table_confirm_path(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        [$pi, $q] = $this->piWithQuotation(QuotationStatus::SENT);

        Livewire::test(ListProformaInvoices::class)
            ->callTableAction('transition_to_confirmed', $pi);

        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $q->fresh()->status->value);
    }
}
