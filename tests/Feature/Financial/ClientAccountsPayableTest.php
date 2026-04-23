<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\AccountsPayableBuilder;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Excel\Templates\ClientAccountsPayableExcelTemplate;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\Templates\ClientAccountsPayablePdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAccountsPayableTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_returns_only_schedule_items_for_company_pis(): void
    {
        $clientA = Company::factory()->create();
        $clientB = Company::factory()->create();

        $piA = ProformaInvoice::factory()->create(['company_id' => $clientA->id]);
        $piB = ProformaInvoice::factory()->create(['company_id' => $clientB->id]);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piA->id,
            'label' => 'Installment A',
            'percentage' => 100,
            'amount' => 10000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piB->id,
            'label' => 'Installment B',
            'percentage' => 100,
            'amount' => 20000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);

        $report = app(AccountsPayableBuilder::class)->build($clientA);

        $this->assertCount(1, $report->rows);
        $this->assertSame($piA->reference ?? 'PI-'.$piA->id, $report->rows[0]['document']);
    }

    public function test_builder_skips_paid_items_when_open_only(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Paid installment',
            'percentage' => 50,
            'amount' => 5000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PAID->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->subDays(10),
        ]);
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Due installment',
            'percentage' => 50,
            'amount' => 5000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 2,
            'due_date' => now()->addDays(5),
        ]);

        $openOnly = app(AccountsPayableBuilder::class)->build($company, openOnly: true);
        $all = app(AccountsPayableBuilder::class)->build($company, openOnly: false);

        $this->assertCount(1, $openOnly->rows);
        $this->assertCount(2, $all->rows);
    }

    public function test_builder_skips_credit_items(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Credit line',
            'percentage' => 0,
            'amount' => 3000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => true,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);

        $report = app(AccountsPayableBuilder::class)->build($company);

        $this->assertCount(0, $report->rows);
    }

    public function test_builder_aggregates_totals_by_currency(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        foreach ([
            ['amount' => 10000, 'currency' => 'USD'],
            ['amount' => 20000, 'currency' => 'USD'],
            ['amount' => 15000, 'currency' => 'CNY'],
        ] as $i => $row) {
            PaymentScheduleItem::create([
                'payable_type' => ProformaInvoice::class,
                'payable_id' => $pi->id,
                'label' => "Item $i",
                'percentage' => 0,
                'amount' => $row['amount'],
                'currency_code' => $row['currency'],
                'status' => PaymentScheduleStatus::DUE->value,
                'is_blocking' => false,
                'is_credit' => false,
                'sort_order' => $i,
                'due_date' => now()->addDays($i + 1),
            ]);
        }

        $report = app(AccountsPayableBuilder::class)->build($company);

        $usd = collect($report->totals)->firstWhere('currency', 'USD');
        $cny = collect($report->totals)->firstWhere('currency', 'CNY');

        $this->assertNotNull($usd);
        $this->assertNotNull($cny);
        // Money::SCALE = 10000 (4 decimal precision): 30000 minor = 3.00, 15000 = 1.50
        $this->assertEqualsWithDelta(3.00, $usd['balance'], 0.01);
        $this->assertEqualsWithDelta(1.50, $cny['balance'], 0.01);
    }

    public function test_builder_includes_shipment_schedule_items_for_client(): void
    {
        $company = \App\Domain\CRM\Models\Company::factory()->create();
        $otherClient = \App\Domain\CRM\Models\Company::factory()->create();

        $shipmentForClient = \App\Domain\Logistics\Models\Shipment::create([
            'reference' => 'SH-CLIENT-1',
            'company_id' => $company->id,
            'status' => 'draft',
        ]);
        $shipmentForOther = \App\Domain\Logistics\Models\Shipment::create([
            'reference' => 'SH-OTHER-1',
            'company_id' => $otherClient->id,
            'status' => 'draft',
        ]);

        PaymentScheduleItem::create([
            'payable_type' => \App\Domain\Logistics\Models\Shipment::class,
            'payable_id' => $shipmentForClient->id,
            'label' => 'Freight for client',
            'percentage' => 0,
            'amount' => 50000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);
        PaymentScheduleItem::create([
            'payable_type' => \App\Domain\Logistics\Models\Shipment::class,
            'payable_id' => $shipmentForOther->id,
            'label' => 'Freight for other client',
            'percentage' => 0,
            'amount' => 80000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);

        $report = app(AccountsPayableBuilder::class)->build($company);

        $this->assertCount(1, $report->rows);
        $this->assertSame('Freight for client', $report->rows[0]['installment']);
    }

    public function test_builder_excludes_forwarder_payable_shipment_items(): void
    {
        $company = \App\Domain\CRM\Models\Company::factory()->create();
        $shipment = \App\Domain\Logistics\Models\Shipment::create([
            'reference' => 'SH-FWD-1',
            'company_id' => $company->id,
            'status' => 'draft',
        ]);

        PaymentScheduleItem::create([
            'payable_type' => \App\Domain\Logistics\Models\Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Client freight',
            'percentage' => 0,
            'amount' => 50000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);
        PaymentScheduleItem::create([
            'payable_type' => \App\Domain\Logistics\Models\Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Payable to forwarder',
            'percentage' => 0,
            'amount' => 30000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 2,
            'due_date' => now()->addDays(5),
            'notes' => '[forwarder-payable] internal',
        ]);

        $report = app(AccountsPayableBuilder::class)->build($company);

        $this->assertCount(1, $report->rows);
        $this->assertSame('Client freight', $report->rows[0]['installment']);
    }

    public function test_pdf_template_generates_and_versions_document(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => 10000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);

        $report = app(AccountsPayableBuilder::class)->build($company);
        $gen = app(PdfGeneratorService::class);

        $d1 = $gen->generate(new ClientAccountsPayablePdfTemplate($company, 'en', ['report' => $report]));
        $d2 = $gen->generate(new ClientAccountsPayablePdfTemplate($company, 'en', ['report' => $report]));

        $this->assertInstanceOf(Document::class, $d1);
        $this->assertSame(ClientAccountsPayablePdfTemplate::DOCUMENT_TYPE, $d1->type);
        $this->assertSame($company->getMorphClass(), $d1->documentable_type);
        $this->assertSame($company->id, $d1->documentable_id);
        $this->assertSame(1, $d1->version);
        $this->assertSame(2, $d2->version);
    }

    public function test_excel_template_produces_xlsx_file_with_rows_and_totals(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment 1',
            'percentage' => 0,
            'amount' => 10000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(5),
        ]);

        $report = app(AccountsPayableBuilder::class)->build($company);

        $template = new ClientAccountsPayableExcelTemplate(
            $company,
            ['report' => $report, 'locale' => 'en'],
        );
        $path = $template->generate();

        try {
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));
            // xlsx files are zip archives — first bytes are "PK"
            $this->assertSame('PK', substr(file_get_contents($path, false, null, 0, 2), 0, 2));
            $this->assertStringEndsWith('.xlsx', $template->getFilename());
        } finally {
            @unlink($path);
        }
    }

    public function test_isolated_report_does_not_flag_due_date_scoping_period(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Within range',
            'percentage' => 0,
            'amount' => 10000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(10),
        ]);
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Outside range',
            'percentage' => 0,
            'amount' => 20000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 2,
            'due_date' => now()->addDays(100),
        ]);

        $report = app(AccountsPayableBuilder::class)->build(
            company: $company,
            from: CarbonImmutable::now()->startOfDay(),
            to: CarbonImmutable::now()->addDays(30)->endOfDay(),
        );

        $this->assertCount(1, $report->rows);
        $this->assertSame('Within range', $report->rows[0]['installment']);
    }
}
