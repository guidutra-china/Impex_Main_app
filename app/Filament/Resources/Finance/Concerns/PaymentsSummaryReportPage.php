<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Reports\DTOs\PaymentsSummaryFilters;
use App\Domain\Financial\Reports\DTOs\PaymentsSummaryReport;
use App\Domain\Financial\Reports\PaymentsSummaryExcelExporter;
use App\Domain\Financial\Reports\PaymentsSummaryReportService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

abstract class PaymentsSummaryReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.finance.reports.payments-summary';

    public ?array $data = [];

    abstract public function direction(): PaymentDirection;

    public function mount(): void
    {
        $this->form->fill($this->defaultFilterState());
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'md' => 4, 'lg' => 5])->schema([
                    DatePicker::make('fromDate')
                        ->label(__('payments_summary_report.filters.from'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live(debounce: 300),

                    DatePicker::make('toDate')
                        ->label(__('payments_summary_report.filters.to'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live(debounce: 300),

                    Select::make('approvedOnly')
                        ->label(__('payments_summary_report.filters.status'))
                        ->options([
                            '1' => __('payments_summary_report.filters.status_approved_only'),
                            '0' => __('payments_summary_report.filters.status_include_pending'),
                        ])
                        ->selectablePlaceholder(false)
                        ->live(),

                    Select::make('companyIds')
                        ->label(__('payments_summary_report.filters.companies'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn () => $this->getCompanyOptions())
                        ->columnSpan(['md' => 2, 'lg' => 1])
                        ->live(),

                    Select::make('currencies')
                        ->label(__('payments_summary_report.filters.currencies'))
                        ->multiple()
                        ->options(fn () => $this->getCurrencyOptions())
                        ->live(),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label(__('payments_summary_report.filters.reset'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->form->fill($this->defaultFilterState())),

            Action::make('export')
                ->label(__('payments_summary_report.filters.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->action(fn () => $this->exportToExcel()),
        ];
    }

    public function getReportProperty(): PaymentsSummaryReport
    {
        $data = $this->data ?? [];

        $filters = new PaymentsSummaryFilters(
            direction: $this->direction(),
            from: CarbonImmutable::parse($data['fromDate'] ?? now()->startOfYear()->toDateString()),
            to: CarbonImmutable::parse($data['toDate'] ?? now()->toDateString()),
            companyIds: array_map('intval', array_values($data['companyIds'] ?? [])),
            currencies: array_values($data['currencies'] ?? []),
            approvedOnly: (bool) ($data['approvedOnly'] ?? true),
        );

        return app(PaymentsSummaryReportService::class)->build($filters);
    }

    public function exportToExcel(): BinaryFileResponse
    {
        $report = $this->report;

        $directionLabel = $this->direction()->value === 'inbound' ? 'receivables' : 'payables';
        $filename = sprintf(
            '%s_%s_%s.xlsx',
            $directionLabel,
            $report->filters->from->format('Y-m-d'),
            $report->filters->to->format('Y-m-d'),
        );

        $tmpDir = storage_path('app/temp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.'/'.uniqid('payments_summary_', true).'.xlsx';

        app(PaymentsSummaryExcelExporter::class)->export($report, $tmpPath);

        return response()
            ->download($tmpPath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    protected function defaultFilterState(): array
    {
        return [
            'fromDate' => now()->startOfYear()->toDateString(),
            'toDate' => now()->toDateString(),
            'companyIds' => [],
            'currencies' => [],
            'approvedOnly' => '1',
        ];
    }

    protected function getCompanyOptions(): array
    {
        return Payment::query()
            ->where('direction', $this->direction())
            ->join('companies', 'companies.id', '=', 'payments.company_id')
            ->select('companies.id', 'companies.name')
            ->distinct()
            ->orderBy('companies.name')
            ->pluck('companies.name', 'companies.id')
            ->all();
    }

    protected function getCurrencyOptions(): array
    {
        $codes = Payment::query()
            ->where('direction', $this->direction())
            ->whereNotNull('currency_code')
            ->distinct()
            ->orderBy('currency_code')
            ->pluck('currency_code')
            ->all();

        return array_combine($codes, $codes) ?: [];
    }
}
