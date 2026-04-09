<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
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
            $renderer = app(PdfRenderer::class);
            $pdfBinary = $renderer->render('statements.preview', ['report' => $report]);
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
