<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DocumentBalance;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\FinancialSummaryBuilder;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documentos cancelados e parcelas waived não podem constar nos balances
 * dos relatórios financeiros.
 */
class ReportsExcludeCancelledAndWaivedTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
    }

    private function makePi(string $status, int $itemTotal = 100_000): ProformaInvoice
    {
        $inquiry = Inquiry::factory()->create(['company_id' => $this->client->id]);

        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'status' => $status,
            'issue_date' => now()->toDateString(),
        ]);

        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'quantity' => 1,
            'unit_price' => $itemTotal,
        ]);

        return $pi;
    }

    private function makePsi(ProformaInvoice $pi, string $status, int $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => $status,
            'due_date' => now()->subDays(10)->toDateString(),
            'sort_order' => 1,
        ]);
    }

    public function test_open_items_query_excludes_installments_of_cancelled_documents(): void
    {
        $active = $this->makePi('confirmed');
        $cancelled = $this->makePi('cancelled');

        $openOfActive = $this->makePsi($active, PaymentScheduleStatus::PENDING->value, 100_000);
        $openOfCancelled = $this->makePsi($cancelled, PaymentScheduleStatus::PENDING->value, 100_000);

        $ids = OpenScheduleItemsQuery::receivables()->pluck('id');

        $this->assertTrue($ids->contains($openOfActive->id));
        $this->assertFalse($ids->contains($openOfCancelled->id), 'parcela de PI cancelada não pode entrar no aberto');
    }

    public function test_statement_summary_ignores_cancelled_documents_and_waived_installments(): void
    {
        // PI ativa de 100k com 40k waived → open esperado 60k.
        $active = $this->makePi('confirmed', 100_000);
        $this->makePsi($active, PaymentScheduleStatus::PENDING->value, 60_000);
        $this->makePsi($active, PaymentScheduleStatus::WAIVED->value, 40_000);

        // PI cancelada de 500k não pode aparecer em nada.
        $this->makePi('cancelled', 500_000);

        $filters = new StatementFilters(
            from: CarbonImmutable::now()->subDay(),
            to: CarbonImmutable::now()->addDay(),
            statusScope: 'all',
            sectionKeys: [],
            currency: null,
            locale: 'en',
        );

        $summary = (new FinancialSummaryBuilder)->build($this->client, CompanyRole::CLIENT, $filters);

        $this->assertNotNull($summary);
        $totals = collect($summary->totalsByCurrency)->firstWhere('currency', 'USD');
        $this->assertSame(10.0, $totals->invoiced, 'só a PI ativa (100k minor = 10.00) entra no invoiced');
        $this->assertSame(6.0, $totals->open, 'waived (40k) sai do saldo em aberto');

        // Aging: a parcela waived vencida não envelhece; a pendente sim.
        $aging = collect($summary->agingByCurrency)->firstWhere('currency', 'USD');
        $this->assertSame(6.0, $aging->bucket0to30);
    }

    public function test_document_balance_is_zero_for_cancelled_and_discounts_waived(): void
    {
        $cancelled = $this->makePi('cancelled', 100_000);
        $this->makePsi($cancelled, PaymentScheduleStatus::PENDING->value, 100_000);
        $this->assertSame(0, DocumentBalance::open($cancelled, 100_000, 0));

        $active = $this->makePi('confirmed', 100_000);
        $this->makePsi($active, PaymentScheduleStatus::WAIVED->value, 30_000);
        $this->assertSame(70_000, DocumentBalance::open($active, 100_000, 0));
    }
}
