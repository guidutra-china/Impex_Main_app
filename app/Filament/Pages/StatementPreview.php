<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class StatementPreview extends Page
{
    protected string $view = 'filament.pages.statement-preview';

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public string $statusScope = 'all';
    public array $sectionToggles = [];
    public ?string $currency = null;
    public string $locale = 'en';

    abstract protected function resolveCompany(): Company;

    protected function initializeStatement(): void
    {
        abort_unless(auth()->user()?->can('view-statements'), 403);

        $company = $this->resolveCompany();

        $this->fromDate = now()->startOfYear()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
        $this->locale = $company->preferred_language
            ?? auth()->user()?->locale
            ?? config('app.locale');

        foreach (['inquiries', 'quotations', 'proforma_invoices', 'shipments', 'purchase_orders', 'rfqs'] as $section) {
            $this->sectionToggles[$section] = true;
        }
    }

    /** Computed property — rebuilt each render, not serialized by Livewire. */
    public function getReportProperty(): ?StatementReport
    {
        return $this->buildReport();
    }

    public function generate(): void
    {
        // Triggers re-render which calls getReportProperty().
    }

    public function downloadPdf(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('view-statements'), 403);

        $report = $this->buildReport();

        $previous = App::getLocale();
        try {
            App::setLocale($report->locale);

            $companySettings = app(CompanySettings::class);
            $logoPath = $this->resolveLogoPath($companySettings->logo_path);

            $pdfData = [
                'report' => $report,
                'title' => __('statements.title'),
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
            $pdfBinary = $renderer->render('pdf.statement', $pdfData);
        } finally {
            App::setLocale($previous);
        }

        $filename = 'statement-' . \Illuminate\Support\Str::slug($report->company->name)
            . '-' . $report->generatedAt->format('Y-m-d') . '.pdf';

        return response()->streamDownload(
            fn () => print($pdfBinary),
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
            storage_path('app/public/' . $logoPath),
            storage_path('app/' . $logoPath),
            public_path('storage/' . $logoPath),
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

    protected function buildReport(): StatementReport
    {
        $company = $this->resolveCompany();

        $sectionKeys = array_values(array_filter(
            ['inquiries', 'quotations', 'proforma_invoices', 'shipments', 'purchase_orders', 'rfqs'],
            fn (string $key) => ($this->sectionToggles[$key] ?? false) === true,
        ));

        $filters = new StatementFilters(
            from: CarbonImmutable::parse($this->fromDate)->startOfDay(),
            to: CarbonImmutable::parse($this->toDate)->endOfDay(),
            statusScope: $this->statusScope,
            sectionKeys: $sectionKeys,
            currency: $this->currency !== '' ? $this->currency : null,
            locale: $this->locale,
        );

        $previous = App::getLocale();
        try {
            App::setLocale($this->locale);

            return app(CompanyStatementService::class)->build($company, $filters);
        } finally {
            App::setLocale($previous);
        }
    }
}
