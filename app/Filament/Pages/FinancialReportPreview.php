<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyFinancialReportService;
use App\Domain\CRM\Reports\DTOs\FinancialReportData;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\Infrastructure\Excel\Templates\FinancialReportExcelTemplate;
use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class FinancialReportPreview extends Page
{
    protected string $view = 'filament.pages.financial-report-preview';

    public ?string $fromDate = null;

    public ?string $toDate = null;

    public string $statusScope = 'all';

    public array $sectionToggles = [];

    public ?string $currency = null;

    public string $locale = 'en';

    abstract protected function resolveCompany(): Company;

    abstract protected function reportContext(): string;

    /** @return list<string> */
    protected function availableSections(): array
    {
        return ['proforma_invoices', 'purchase_orders', 'shipments', 'payments', 'debit_notes', 'credit_notes', 'additional_costs', 'margin_analysis'];
    }

    protected function initializeReport(): void
    {
        $company = $this->resolveCompany();

        $this->fromDate = now()->startOfYear()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
        $this->locale = $company->preferred_language
            ?? auth()->user()?->locale
            ?? config('app.locale');

        foreach ($this->availableSections() as $section) {
            $this->sectionToggles[$section] = true;
        }
    }

    public function getReportProperty(): ?FinancialReportData
    {
        return $this->buildReport();
    }

    public function generate(): void
    {
        // Triggers re-render which calls getReportProperty().
    }

    /**
     * Subclasses override to opt-in to the Excel export button in the view.
     * Default is false so Portal/Supplier variants stay PDF-only unless explicitly enabled.
     */
    public function shouldShowExcelExport(): bool
    {
        return false;
    }

    public function downloadExcel(): StreamedResponse
    {
        $report = $this->buildReport();

        $previous = App::getLocale();
        try {
            App::setLocale($report->locale);

            $template = new FinancialReportExcelTemplate(
                $report->company,
                ['report' => $report, 'locale' => $report->locale],
            );
            $path = $template->generate();
            $filename = $template->getFilename();
            $content = file_get_contents($path);
            @unlink($path);
        } finally {
            App::setLocale($previous);
        }

        return response()->streamDownload(
            fn () => print ($content),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function downloadPdf(): StreamedResponse
    {
        $report = $this->buildReport();

        $previous = App::getLocale();
        try {
            App::setLocale($report->locale);

            $companySettings = app(CompanySettings::class);
            $logoPath = $this->resolveLogoPath($companySettings->logo_path);

            $pdfData = [
                'report' => $report,
                'title' => __('financial_report.title'),
                'locale' => $report->locale,
                'labels' => DocumentLabels::all($report->locale),
                'company' => [
                    'name' => $companySettings->company_name,
                    'logo_path' => $logoPath,
                    'address' => $companySettings->address,
                    'city' => $companySettings->city,
                    'state' => $companySettings->state,
                    'zip_code' => $companySettings->zip_code,
                    'country' => $companySettings->country,
                    'phone' => $companySettings->phone,
                    'email' => $companySettings->email,
                    'website' => $companySettings->website,
                    'tax_id' => $companySettings->tax_id,
                    'registration_number' => $companySettings->registration_number,
                    'footer_text' => $companySettings->footer_text,
                    'bank_details' => $companySettings->bank_details_for_documents,
                ],
                'document_version' => 1,
            ];

            $renderer = app(PdfRenderer::class);
            $pdfBinary = $renderer->render('pdf.financial-report', $pdfData, 'a4', 'landscape');
        } finally {
            App::setLocale($previous);
        }

        $filename = 'financial-report-'.\Illuminate\Support\Str::slug($report->company->name)
            .'-'.$report->generatedAt->format('Y-m-d').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdfBinary),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function resolveLogoPath(?string $logoPath): ?string
    {
        if (empty($logoPath)) {
            return null;
        }

        $candidates = [
            storage_path('app/public/'.$logoPath),
            storage_path('app/'.$logoPath),
            public_path('storage/'.$logoPath),
            public_path($logoPath),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $mime = mime_content_type($path);
                $data = base64_encode(file_get_contents($path));

                return "data:{$mime};base64,{$data}";
            }
        }

        return null;
    }

    protected function buildReport(): FinancialReportData
    {
        $company = $this->resolveCompany();

        $sectionKeys = array_values(array_filter(
            $this->availableSections(),
            fn (string $key) => ($this->sectionToggles[$key] ?? false) === true,
        ));

        $filters = new FinancialReportFilters(
            from: CarbonImmutable::parse($this->fromDate)->startOfDay(),
            to: CarbonImmutable::parse($this->toDate)->endOfDay(),
            statusScope: $this->statusScope,
            sectionKeys: $sectionKeys,
            currency: $this->currency !== '' ? $this->currency : null,
            locale: $this->locale,
            context: $this->reportContext(),
        );

        $previous = App::getLocale();
        try {
            App::setLocale($this->locale);

            return app(CompanyFinancialReportService::class)->build($company, $filters);
        } finally {
            App::setLocale($previous);
        }
    }
}
