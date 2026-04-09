<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminStatementPreview extends Page
{
    protected string $view = 'filament.pages.statement-preview';

    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'statement';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'company')]
    public ?int $companyId = null;

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public string $statusScope = 'all';
    public array $sectionToggles = [];
    public ?string $currency = null;
    public string $locale = 'en';

    public const AVAILABLE_SECTIONS = [
        'inquiries',
        'quotations',
        'proforma_invoices',
        'shipments',
        'purchase_orders',
        'rfqs',
    ];

    public const AVAILABLE_LOCALES = [
        'en' => 'English',
        'pt_BR' => 'Português (Brasil)',
        'zh_CN' => '中文 (简体)',
    ];

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);
        abort_unless(auth()->user()?->can('view-statements'), 403);

        $company = $this->resolveCompany();

        $this->fromDate = now()->startOfYear()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
        $this->locale = $company->preferred_language
            ?? auth()->user()?->locale
            ?? config('app.locale');

        foreach (self::AVAILABLE_SECTIONS as $section) {
            $this->sectionToggles[$section] = true;
        }
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('statements.title') . ' — ' . $this->resolveCompany()->name;
    }

    /** Computed property — rebuilt each render, not serialized by Livewire. */
    public function getReportProperty(): ?StatementReport
    {
        return $this->buildReport();
    }

    public function generate(): void
    {
        // Livewire action — triggers re-render which calls getReportProperty().
        // Nothing extra needed; filter properties already updated via wire:model.
    }

    public function downloadPdf(): StreamedResponse
    {
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
            self::AVAILABLE_SECTIONS,
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

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }
}
