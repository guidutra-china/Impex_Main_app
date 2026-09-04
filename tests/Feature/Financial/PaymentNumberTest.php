<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyFinancialReportService;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Support\PaymentNumberBackfill;
use App\Domain\Infrastructure\Models\ReferenceSequence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pagamento era o único documento sem numeração: os relatórios imprimiam o
 * campo livre "Referência (SWIFT)" como se fosse o número, vazio em 9 de
 * cada 10 pagamentos. Agora nasce com PAY-YYYY-NNNNNN via reference_sequences.
 */
class PaymentNumberTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->company->companyRoles()->create(['role' => 'client']);
    }

    /** @param array<string, mixed> $overrides */
    private function makePayment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->company->id,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-09-04',
            'status' => PaymentStatus::APPROVED,
        ], $overrides));
    }

    public function test_new_payment_gets_a_sequential_number_and_keeps_swift_reference_free(): void
    {
        $year = now()->year;

        $first = $this->makePayment(['reference' => 'SWIFT-123']);
        $second = $this->makePayment();

        $this->assertSame("PAY-{$year}-000001", $first->number);
        $this->assertSame("PAY-{$year}-000002", $second->number);
        $this->assertSame('SWIFT-123', $first->reference);
        $this->assertNull($second->reference);

        $explicit = $this->makePayment(['number' => 'PAY-2020-000009']);
        $this->assertSame('PAY-2020-000009', $explicit->number);
    }

    public function test_backfill_numbers_legacy_rows_by_creation_order_including_trashed_and_seeds_sequence(): void
    {
        // Legacy rows inserted straight into the table: no number, like prod.
        $insert = fn (string $createdAt, ?string $deletedAt = null) => DB::table('payments')->insertGetId([
            'direction' => 'inbound',
            'company_id' => $this->company->id,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-01-10',
            'status' => 'approved',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => $deletedAt,
        ]);

        $late = $insert('2026-03-01 10:00:00');
        $early = $insert('2026-01-05 10:00:00');
        $trashed = $insert('2026-02-01 10:00:00', '2026-02-02 00:00:00');
        $lastYear = $insert('2025-12-20 10:00:00');

        $this->assertSame(4, PaymentNumberBackfill::run());

        $number = fn (int $id) => DB::table('payments')->where('id', $id)->value('number');
        $this->assertSame('PAY-2025-000001', $number($lastYear));
        $this->assertSame('PAY-2026-000001', $number($early));
        $this->assertSame('PAY-2026-000002', $number($trashed), 'Soft-deleted rows keep their number so it is never reissued.');
        $this->assertSame('PAY-2026-000003', $number($late));

        $this->assertSame(4, ReferenceSequence::where('type', 'PAY')->where('year', 2026)->value('next_number'));
        $this->assertSame(2, ReferenceSequence::where('type', 'PAY')->where('year', 2025)->value('next_number'));

        // Idempotent, and a payment created afterwards continues the sequence.
        $this->assertSame(0, PaymentNumberBackfill::run());
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $this->assertSame('PAY-2026-000004', $this->makePayment()->number);
        CarbonImmutable::setTestNow();
    }

    public function test_payment_section_of_financial_report_shows_the_number(): void
    {
        $payment = $this->makePayment(['payment_date' => now()->subDays(2)->toDateString()]);

        $filters = new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth()->startOfDay(),
            to: CarbonImmutable::now()->endOfDay(),
            statusScope: 'all',
            sectionKeys: ['payments'],
            currency: null,
            locale: 'en',
            context: 'admin',
        );
        $report = app(CompanyFinancialReportService::class)->build($this->company, $filters);

        $section = collect($report->sections)->firstWhere('key', 'payments');
        $header = collect($section->rows)->firstWhere('_row_type', 'header');
        $this->assertSame($payment->number, $header['reference']);
    }
}
