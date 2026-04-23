<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyFinancialReportService;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\Templates\CompanyFinancialStatementPdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFinancialReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excluded_ids_are_filtered_out_of_section_rows(): void
    {
        $company = Company::factory()->create();
        $piKept = ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'issue_date' => now()->subDays(5),
        ]);
        $piExcluded = ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'issue_date' => now()->subDays(3),
        ]);

        $filters = new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth()->startOfDay(),
            to: CarbonImmutable::now()->endOfDay(),
            statusScope: 'all',
            sectionKeys: ['proforma_invoices'],
            currency: null,
            locale: 'en',
            context: 'admin',
            excluded: ['proforma_invoices' => [$piExcluded->id]],
        );

        $report = app(CompanyFinancialReportService::class)->build($company, $filters);

        $piSection = collect($report->sections)->firstWhere('key', 'proforma_invoices');
        $this->assertNotNull($piSection);

        $headerIds = collect($piSection->rows)
            ->where('_row_type', 'header')
            ->pluck('_entity_id')
            ->all();

        $this->assertContains($piKept->id, $headerIds);
        $this->assertNotContains($piExcluded->id, $headerIds);
    }

    public function test_is_excluded_helper_returns_expected_results(): void
    {
        $filters = new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth(),
            to: CarbonImmutable::now(),
            statusScope: 'all',
            sectionKeys: [],
            currency: null,
            locale: 'en',
            context: 'admin',
            excluded: ['shipments' => [42, 7]],
        );

        $this->assertTrue($filters->isExcluded('shipments', 42));
        $this->assertTrue($filters->isExcluded('shipments', 7));
        $this->assertFalse($filters->isExcluded('shipments', 999));
        $this->assertFalse($filters->isExcluded('proforma_invoices', 42));
    }

    public function test_pdf_template_generates_document_attached_to_company(): void
    {
        $company = Company::factory()->create();
        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'issue_date' => now()->subDays(2),
        ]);

        $filters = new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth()->startOfDay(),
            to: CarbonImmutable::now()->endOfDay(),
            statusScope: 'all',
            sectionKeys: ['proforma_invoices'],
            currency: null,
            locale: 'en',
            context: 'admin',
        );

        $report = app(CompanyFinancialReportService::class)->build($company, $filters);

        $template = new CompanyFinancialStatementPdfTemplate(
            model: $company,
            locale: 'en',
            options: ['report' => $report],
        );

        $document = app(PdfGeneratorService::class)->generate($template);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame(CompanyFinancialStatementPdfTemplate::DOCUMENT_TYPE, $document->type);
        $this->assertSame($company->getMorphClass(), $document->documentable_type);
        $this->assertSame($company->id, $document->documentable_id);
        $this->assertSame(1, $document->version);
    }

    public function test_show_details_false_omits_detail_rows_from_section_builders(): void
    {
        $company = Company::factory()->create();
        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'issue_date' => now()->subDays(2),
        ]);

        $baseFiltersArgs = [
            'from' => CarbonImmutable::now()->subMonth()->startOfDay(),
            'to' => CarbonImmutable::now()->endOfDay(),
            'statusScope' => 'all',
            'sectionKeys' => ['proforma_invoices'],
            'currency' => null,
            'locale' => 'en',
            'context' => 'admin',
        ];

        $withDetails = new FinancialReportFilters(...$baseFiltersArgs, showDetails: true);
        $withoutDetails = new FinancialReportFilters(...$baseFiltersArgs, showDetails: false);

        $withRows = collect(app(CompanyFinancialReportService::class)->build($company, $withDetails)->sections)
            ->firstWhere('key', 'proforma_invoices')
            ->rows;
        $withoutRows = collect(app(CompanyFinancialReportService::class)->build($company, $withoutDetails)->sections)
            ->firstWhere('key', 'proforma_invoices')
            ->rows;

        $this->assertContains('header', array_column($withRows, '_row_type'));
        $this->assertContains('header', array_column($withoutRows, '_row_type'));
        $this->assertNotContains('detail', array_column($withoutRows, '_row_type'));
    }

    public function test_pdf_template_increments_version_for_same_company(): void
    {
        $company = Company::factory()->create();
        $filters = new FinancialReportFilters(
            from: CarbonImmutable::now()->subMonth(),
            to: CarbonImmutable::now(),
            statusScope: 'all',
            sectionKeys: ['proforma_invoices'],
            currency: null,
            locale: 'en',
            context: 'admin',
        );
        $report = app(CompanyFinancialReportService::class)->build($company, $filters);

        $gen = app(PdfGeneratorService::class);
        $d1 = $gen->generate(new CompanyFinancialStatementPdfTemplate($company, 'en', ['report' => $report]));
        $d2 = $gen->generate(new CompanyFinancialStatementPdfTemplate($company, 'en', ['report' => $report]));

        $this->assertSame(1, $d1->version);
        $this->assertSame(2, $d2->version);
    }
}
