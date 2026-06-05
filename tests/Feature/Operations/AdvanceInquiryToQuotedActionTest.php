<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Actions\AdvanceInquiryToQuotedAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvanceInquiryToQuotedActionTest extends TestCase
{
    use RefreshDatabase;

    private function advance(Inquiry $inquiry): void
    {
        app(AdvanceInquiryToQuotedAction::class)->execute($inquiry);
    }

    public function test_walks_received_inquiry_to_quoted(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);

        $this->advance($inquiry);

        $this->assertSame(InquiryStatus::QUOTED->value, $inquiry->fresh()->status->value);
        // Walked received→quoting→quoted: two audit rows.
        $this->assertSame(2, $inquiry->stateTransitions()->count());
    }

    public function test_advances_quoting_inquiry_to_quoted(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTING->value]);

        $this->advance($inquiry);

        $this->assertSame(InquiryStatus::QUOTED->value, $inquiry->fresh()->status->value);
        $this->assertSame(1, $inquiry->stateTransitions()->count());
    }

    public function test_is_noop_when_already_quoted(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTED->value]);

        $this->advance($inquiry);

        $this->assertSame(InquiryStatus::QUOTED->value, $inquiry->fresh()->status->value);
        $this->assertSame(0, $inquiry->stateTransitions()->count());
    }

    public function test_is_noop_when_won(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::WON->value]);

        $this->advance($inquiry);

        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(0, $inquiry->stateTransitions()->count());
    }

    public function test_is_noop_for_null(): void
    {
        app(AdvanceInquiryToQuotedAction::class)->execute(null);

        $this->assertTrue(true); // no exception
    }
}
