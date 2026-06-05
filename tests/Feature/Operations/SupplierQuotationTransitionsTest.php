<?php

namespace Tests\Feature\Operations;

use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierQuotationTransitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_can_transition_directly_to_selected(): void
    {
        $sq = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::RECEIVED->value]);

        $this->assertTrue($sq->canTransitionTo(SupplierQuotationStatus::SELECTED->value));

        $sq->transitionTo(SupplierQuotationStatus::SELECTED->value);

        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
    }

    public function test_under_analysis_still_reaches_selected(): void
    {
        $sq = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::UNDER_ANALYSIS->value]);

        $this->assertTrue($sq->canTransitionTo(SupplierQuotationStatus::SELECTED->value));
    }

    public function test_requested_cannot_jump_to_selected(): void
    {
        $sq = SupplierQuotation::factory()->create(['status' => SupplierQuotationStatus::REQUESTED->value]);

        $this->assertFalse($sq->canTransitionTo(SupplierQuotationStatus::SELECTED->value));
    }
}
