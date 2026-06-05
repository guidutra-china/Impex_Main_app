<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Models\StateTransition;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileConfirmedProformaInvoicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a fully inconsistent confirmed PI scenario:
     * - Inquiry at given status
     * - ProformaInvoice confirmed (or given status)
     * - SupplierQuotation at given SQ status linked by inquiry_id
     * - Quotation at given quotation status linked by inquiry_id
     *
     * Returns [$pi, $inquiry, $sq, $quotation].
     */
    private function buildScenario(
        InquiryStatus $inquiryStatus = InquiryStatus::QUOTING,
        ProformaInvoiceStatus $piStatus = ProformaInvoiceStatus::CONFIRMED,
        SupplierQuotationStatus $sqStatus = SupplierQuotationStatus::RECEIVED,
        QuotationStatus $quotationStatus = QuotationStatus::SENT,
        int $quotationVersion = 1,
    ): array {
        $inquiry = Inquiry::factory()->create(['status' => $inquiryStatus->value]);

        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => $piStatus->value,
        ]);

        $sq = SupplierQuotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => $sqStatus->value,
        ]);

        $quotation = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => $quotationStatus->value,
            'version' => $quotationVersion,
        ]);

        return [$pi, $inquiry, $sq, $quotation];
    }

    // -------------------------------------------------------------------------

    public function test_reconciles_a_fully_inconsistent_confirmed_pi(): void
    {
        [$pi, $inquiry, $sq, $quotation] = $this->buildScenario(
            InquiryStatus::QUOTING,
            ProformaInvoiceStatus::CONFIRMED,
            SupplierQuotationStatus::RECEIVED,
            QuotationStatus::SENT,
        );

        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $quotation->fresh()->status->value);
    }

    public function test_walks_a_received_inquiry_to_won(): void
    {
        [$pi, $inquiry, $sq, $quotation] = $this->buildScenario(
            InquiryStatus::RECEIVED,
            ProformaInvoiceStatus::CONFIRMED,
            SupplierQuotationStatus::RECEIVED,
            QuotationStatus::SENT,
        );

        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        // Inquiry walked: received → quoting → quoted → won
        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $quotation->fresh()->status->value);
    }

    public function test_is_idempotent(): void
    {
        [$pi, $inquiry, $sq, $quotation] = $this->buildScenario();

        // First run — applies advances.
        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $quotation->fresh()->status->value);

        // Count transitions after first run.
        $transitionsBefore = StateTransition::count();

        // Second run — must not create new transitions.
        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        $transitionsAfter = StateTransition::count();
        $this->assertSame($transitionsBefore, $transitionsAfter, 'Second run must not create new state transitions');

        // Statuses remain the same.
        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $quotation->fresh()->status->value);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$pi, $inquiry, $sq, $quotation] = $this->buildScenario();

        $this->artisan('operations:reconcile-confirmed-pis --dry-run')->assertSuccessful();

        // All statuses must remain in their original inconsistent state.
        $this->assertSame(InquiryStatus::QUOTING->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::RECEIVED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::SENT->value, $quotation->fresh()->status->value);
    }

    public function test_ignores_non_confirmed_pis(): void
    {
        // DRAFT PI — should not be touched.
        $draftInquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTING->value]);
        ProformaInvoice::factory()->create([
            'inquiry_id' => $draftInquiry->id,
            'status' => ProformaInvoiceStatus::DRAFT->value,
        ]);

        // CANCELLED PI — should not be touched.
        $cancelledInquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTING->value]);
        ProformaInvoice::factory()->create([
            'inquiry_id' => $cancelledInquiry->id,
            'status' => ProformaInvoiceStatus::CANCELLED->value,
        ]);

        // SENT PI — should not be touched.
        $sentInquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTING->value]);
        ProformaInvoice::factory()->create([
            'inquiry_id' => $sentInquiry->id,
            'status' => ProformaInvoiceStatus::SENT->value,
        ]);

        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        // None of these inquiries should have been advanced.
        $this->assertSame(InquiryStatus::QUOTING->value, $draftInquiry->fresh()->status->value);
        $this->assertSame(InquiryStatus::QUOTING->value, $cancelledInquiry->fresh()->status->value);
        $this->assertSame(InquiryStatus::QUOTING->value, $sentInquiry->fresh()->status->value);
    }

    public function test_only_latest_quotation_version_approved(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::QUOTED->value]);

        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        // v1 — older version, sent
        $v1 = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::SENT->value,
            'version' => 1,
        ]);

        // v2 — latest version, sent
        $v2 = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::SENT->value,
            'version' => 2,
        ]);

        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        // Only v2 (latest) should be approved.
        $this->assertSame(QuotationStatus::APPROVED->value, $v2->fresh()->status->value);
        // v1 must remain sent.
        $this->assertSame(QuotationStatus::SENT->value, $v1->fresh()->status->value);
    }

    public function test_skips_already_consistent_pi(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::WON->value]);

        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        $sq = SupplierQuotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => SupplierQuotationStatus::SELECTED->value,
        ]);

        $quotation = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $inquiry->company_id,
            'status' => QuotationStatus::APPROVED->value,
            'version' => 1,
        ]);

        $transitionsBefore = StateTransition::count();

        $this->artisan('operations:reconcile-confirmed-pis')->assertSuccessful();

        // No new transitions created — everything was already in place.
        $this->assertSame($transitionsBefore, StateTransition::count());

        // All statuses remain as they were.
        $this->assertSame(InquiryStatus::WON->value, $inquiry->fresh()->status->value);
        $this->assertSame(SupplierQuotationStatus::SELECTED->value, $sq->fresh()->status->value);
        $this->assertSame(QuotationStatus::APPROVED->value, $quotation->fresh()->status->value);
    }
}
