<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyFinancialReportService;
use App\Domain\CRM\Reports\DTOs\FinancialReportData;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\Templates\CompanyFinancialStatementPdfTemplate;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use App\Mail\DocumentMail;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomFinancialReport extends FinancialReportPreview
{
    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'custom-financial-report';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.custom-financial-report';

    #[Url(as: 'company')]
    public ?int $companyId = null;

    /** @var array<string,list<int>> */
    public array $excluded = [
        'proforma_invoices' => [],
        'purchase_orders' => [],
        'shipments' => [],
        'payments' => [],
        'debit_notes' => [],
        'credit_notes' => [],
        'additional_costs' => [],
    ];

    public bool $showDetails = true;

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);

        $this->initializeReport();
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('custom_financial_report.title').' — '.$this->resolveCompany()->name;
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    protected function reportContext(): string
    {
        return 'admin';
    }

    public function toggleRow(string $section, int $id): void
    {
        $current = $this->excluded[$section] ?? [];

        if (in_array($id, $current, true)) {
            $this->excluded[$section] = array_values(array_diff($current, [$id]));
        } else {
            $current[] = $id;
            $this->excluded[$section] = $current;
        }
    }

    public function clearExclusions(): void
    {
        foreach ($this->excluded as $key => $_) {
            $this->excluded[$key] = [];
        }
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
            excluded: $this->normalizedExcluded(),
            showDetails: $this->showDetails,
        );

        $previous = App::getLocale();
        try {
            App::setLocale($this->locale);

            return app(CompanyFinancialReportService::class)->build($company, $filters);
        } finally {
            App::setLocale($previous);
        }
    }

    public function downloadPdf(): StreamedResponse
    {
        return parent::downloadPdf();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('financial_report.actions.back'))
                ->url(CompanyResource::getUrl('view', ['record' => $this->companyId]))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),

            Action::make('clearExclusions')
                ->label(__('custom_financial_report.actions.clear_exclusions'))
                ->color('gray')
                ->icon('heroicon-o-arrow-path')
                ->action('clearExclusions'),

            Action::make('downloadPdf')
                ->label(__('financial_report.actions.download_pdf'))
                ->color('primary')
                ->icon('heroicon-o-arrow-down-tray')
                ->action('downloadPdf'),

            Action::make('saveAndSend')
                ->label(__('custom_financial_report.actions.save_and_send'))
                ->color('success')
                ->icon('heroicon-o-paper-airplane')
                ->form(fn () => $this->emailFormSchema())
                ->action(function (array $data): void {
                    $this->saveAndSend($data);
                }),
        ];
    }

    /** @param array<string,mixed> $data */
    protected function saveAndSend(array $data): void
    {
        $company = $this->resolveCompany();
        $report = $this->buildReport();

        $document = app(PdfGeneratorService::class)->generate(
            new CompanyFinancialStatementPdfTemplate(
                model: $company,
                locale: $this->locale,
                options: ['report' => $report],
            ),
        );

        $toAddresses = $this->sanitizeEmails($data['to'] ?? []);
        $ccAddresses = $this->sanitizeEmails($data['cc'] ?? []);

        if (empty($toAddresses)) {
            Notification::make()
                ->title(__('custom_financial_report.notifications.no_recipients'))
                ->danger()
                ->send();

            return;
        }

        try {
            $mail = Mail::to($toAddresses);
            if (! empty($ccAddresses)) {
                $mail->cc($ccAddresses);
            }

            $mail->send(new DocumentMail(
                document: $document,
                recipientName: $data['recipient_name'] ?? $company->name,
                customMessage: (string) ($data['message'] ?? ''),
            ));

            Notification::make()
                ->title(__('custom_financial_report.notifications.sent'))
                ->body(implode(', ', array_merge($toAddresses, $ccAddresses)))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('custom_financial_report.notifications.send_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** @return list<string> */
    private function sanitizeEmails(mixed $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private function emailFormSchema(): array
    {
        $company = $this->resolveCompany();
        $contactOptions = $this->buildContactEmailOptions($company);
        $primary = array_key_first($contactOptions);

        return [
            Select::make('to')
                ->label(__('forms.labels.to'))
                ->options($contactOptions)
                ->default($primary ? [$primary] : [])
                ->multiple()
                ->searchable()
                ->required()
                ->createOptionForm([
                    TextInput::make('email')->label(__('forms.labels.email_address'))->email()->required(),
                ])
                ->createOptionUsing(fn (array $data) => $data['email']),

            TextInput::make('recipient_name')
                ->label(__('forms.labels.recipient_name'))
                ->required()
                ->default($company->name),

            TagsInput::make('cc')
                ->label(__('forms.labels.cc'))
                ->suggestions(array_keys($contactOptions))
                ->splitKeys(['Tab', ',']),

            Textarea::make('message')
                ->label(__('forms.labels.message_optional'))
                ->rows(5)
                ->placeholder(__('custom_financial_report.placeholders.message')),
        ];
    }

    /** @return array<string,string> */
    private function buildContactEmailOptions(Company $company): array
    {
        $options = [];

        $contacts = $company->contacts()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        foreach ($contacts as $contact) {
            $label = $contact->name;
            if ($contact->position) {
                $label .= " ({$contact->position})";
            }
            if ($contact->is_primary) {
                $label .= ' ★';
            }
            $options[$contact->email] = "{$label} — {$contact->email}";
        }

        if ($company->email && ! isset($options[$company->email])) {
            $options[$company->email] = "{$company->name} — {$company->email}";
        }

        return $options;
    }

    public function getPreviouslySentDocuments(): \Illuminate\Support\Collection
    {
        return Document::query()
            ->where('documentable_type', (new Company)->getMorphClass())
            ->where('documentable_id', $this->companyId)
            ->where('type', CompanyFinancialStatementPdfTemplate::DOCUMENT_TYPE)
            ->orderByDesc('version')
            ->limit(10)
            ->get();
    }

    /** @return array<string,list<int>> */
    private function normalizedExcluded(): array
    {
        $normalized = [];
        foreach ($this->excluded as $section => $ids) {
            $normalized[$section] = array_values(array_unique(array_map('intval', $ids)));
        }

        return $normalized;
    }
}
