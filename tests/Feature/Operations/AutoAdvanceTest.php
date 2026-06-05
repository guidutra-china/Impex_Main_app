<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function piWithInquiry(InquiryStatus $inquiryStatus, ProformaInvoiceStatus $piStatus): ProformaInvoice
    {
        $inquiry = Inquiry::factory()->create(['status' => $inquiryStatus->value]);

        return ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => $piStatus->value,
        ]);
    }

    public function test_confirming_pi_advances_quoted_inquiry_to_won(): void
    {
        $pi = $this->piWithInquiry(InquiryStatus::QUOTED, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(InquiryStatus::WON->value, $pi->inquiry->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_touch_a_non_quoted_inquiry(): void
    {
        $pi = $this->piWithInquiry(InquiryStatus::RECEIVED, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(InquiryStatus::RECEIVED->value, $pi->inquiry->fresh()->status->value);
        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
    }

    public function test_pi_confirms_even_if_inquiry_already_won(): void
    {
        $pi = $this->piWithInquiry(InquiryStatus::WON, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
        $this->assertSame(InquiryStatus::WON->value, $pi->inquiry->fresh()->status->value);
    }
}
