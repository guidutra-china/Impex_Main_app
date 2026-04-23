<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\AccountsPayableBuilder;
use App\Domain\CRM\Reports\DTOs\AccountsPayableReport;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\ClientAccountsPayablePdfTemplate;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
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
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientAccountsPayable extends Page
{
    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'client-accounts-payable';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.client-accounts-payable';

    #[Url(as: 'company')]
    public ?int $companyId = null;

    public ?string $fromDate = null;

    public ?string $toDate = null;

    public bool $openOnly = true;

    public string $locale = 'en';

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);

        $company = $this->resolveCompany();
        $this->locale = $company->preferred_language
            ?? auth()->user()?->locale
            ?? config('app.locale');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('client_accounts_payable.title').' — '.$this->resolveCompany()->name;
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    public function getReportProperty(): AccountsPayableReport
    {
        return $this->buildReport();
    }

    public function refresh(): void
    {
        // Trigger re-render
    }

    protected function buildReport(): AccountsPayableReport
    {
        $company = $this->resolveCompany();
        $from = $this->fromDate ? CarbonImmutable::parse($this->fromDate)->startOfDay() : null;
        $to = $this->toDate ? CarbonImmutable::parse($this->toDate)->endOfDay() : null;

        $previous = App::getLocale();
        try {
            App::setLocale($this->locale);

            return app(AccountsPayableBuilder::class)->build(
                company: $company,
                from: $from,
                to: $to,
                openOnly: $this->openOnly,
                locale: $this->locale,
            );
        } finally {
            App::setLocale($previous);
        }
    }

    public function downloadPdf(): StreamedResponse
    {
        $report = $this->buildReport();

        $previous = App::getLocale();
        try {
            App::setLocale($report->locale);

            $settings = app(CompanySettings::class);
            $template = new ClientAccountsPayablePdfTemplate(
                model: $report->company,
                locale: $report->locale,
                options: ['report' => $report],
            );

            $content = app(PdfRenderer::class)->render(
                view: $template->getView(),
                data: $template->getData(),
                paper: $template->getPaper(),
                orientation: $template->getOrientation(),
            );
        } finally {
            App::setLocale($previous);
        }

        $filename = $template->getFilename();

        return response()->streamDownload(
            fn () => print ($content),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('client_accounts_payable.actions.back'))
                ->url(CompanyResource::getUrl('view', ['record' => $this->companyId]))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),

            Action::make('downloadPdf')
                ->label(__('client_accounts_payable.actions.download_pdf'))
                ->color('primary')
                ->icon('heroicon-o-arrow-down-tray')
                ->action('downloadPdf'),

            Action::make('saveAndSend')
                ->label(__('client_accounts_payable.actions.save_and_send'))
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
            new ClientAccountsPayablePdfTemplate(
                model: $company,
                locale: $this->locale,
                options: ['report' => $report],
            ),
        );

        $toAddresses = $this->sanitizeEmails($data['to'] ?? []);
        $ccAddresses = $this->sanitizeEmails($data['cc'] ?? []);

        if (empty($toAddresses)) {
            Notification::make()
                ->title(__('client_accounts_payable.notifications.no_recipients'))
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
                ->title(__('client_accounts_payable.notifications.sent'))
                ->body(implode(', ', array_merge($toAddresses, $ccAddresses)))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('client_accounts_payable.notifications.send_failed'))
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
                ->rows(5),
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
            $label = $contact->name.($contact->position ? " ({$contact->position})" : '').($contact->is_primary ? ' ★' : '');
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
            ->where('type', ClientAccountsPayablePdfTemplate::DOCUMENT_TYPE)
            ->orderByDesc('version')
            ->limit(10)
            ->get();
    }
}
