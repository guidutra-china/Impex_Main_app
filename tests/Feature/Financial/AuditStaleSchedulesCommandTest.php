<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditStaleSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Audit Cmd Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->term = PaymentTerm::create(['name' => 'Audit Cmd Term', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $this->term->id, 'sort_order' => 1,
            'percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
    }

    private function createPiWithSchedule(): ProformaInvoice
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-AUDIT-'.uniqid(),
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-AUDIT-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_term_id' => $this->term->id,
        ]);
        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 100,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);

        app(GeneratePaymentScheduleAction::class)->execute($pi->fresh());

        return $pi->fresh();
    }

    public function test_reports_success_when_no_schedule_is_stale(): void
    {
        $this->createPiWithSchedule();

        $this->artisan('financial:audit-stale-schedules')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    public function test_reports_stale_schedule_with_nonzero_exit_code(): void
    {
        $pi = $this->createPiWithSchedule();
        $pi->items()->first()->update(['unit_price' => 1500]);

        $this->artisan('financial:audit-stale-schedules')
            ->expectsOutputToContain($pi->reference)
            ->assertExitCode(1);
    }

    public function test_fix_regenerates_stale_schedules_and_rerun_is_clean(): void
    {
        $pi = $this->createPiWithSchedule();
        $pi->items()->first()->update(['unit_price' => 1500]);

        $this->artisan('financial:audit-stale-schedules --fix')
            ->expectsOutputToContain($pi->reference)
            ->assertExitCode(0);

        $this->assertSame(
            150_000,
            (int) $pi->fresh()->paymentScheduleItems()->sum('amount'),
            'schedule must reflect the new document total after --fix'
        );

        $this->artisan('financial:audit-stale-schedules')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    public function test_cancelled_documents_are_not_audited(): void
    {
        $pi = $this->createPiWithSchedule();
        $pi->items()->first()->update(['unit_price' => 1500]);
        $pi->update(['status' => 'cancelled']);

        $this->artisan('financial:audit-stale-schedules')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }
}
