<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Reports\DealBreakdownReportService;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\Financial\Reports\DTOs\DealBreakdownReport;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class ClientDealBreakdown extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'client-deal-breakdown';

    protected string $view = 'filament.pages.client-deal-breakdown';

    #[Url(as: 'client')]
    public ?int $clientId = null;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    #[Url(as: 'currency')]
    public ?string $presentationCurrency = null;

    /** @var list<string> */
    #[Url(as: 'statuses')]
    public array $statuses = [];

    /** @var list<int> */
    public array $expandedDeals = [];

    /** @var list<int> */
    public array $expandedShipments = [];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.client_deal_breakdown');
    }

    public function getTitle(): string
    {
        return __('client_deal_breakdown.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public function mount(): void
    {
        $this->fromDate ??= now()->startOfYear()->toDateString();
        $this->toDate ??= now()->toDateString();

        if (empty($this->statuses)) {
            $this->statuses = array_map(
                fn (ProformaInvoiceStatus $s) => $s->value,
                DealBreakdownFilters::defaultStatuses()
            );
        }

        if ($this->clientId && $this->presentationCurrency === null) {
            $this->presentationCurrency = $this->resolveDefaultPresentationCurrency();
        }
    }

    public function toggleDeal(int $piId): void
    {
        $this->expandedDeals = in_array($piId, $this->expandedDeals, true)
            ? array_values(array_diff($this->expandedDeals, [$piId]))
            : array_merge($this->expandedDeals, [$piId]);
    }

    public function toggleShipment(int $shipmentId): void
    {
        $this->expandedShipments = in_array($shipmentId, $this->expandedShipments, true)
            ? array_values(array_diff($this->expandedShipments, [$shipmentId]))
            : array_merge($this->expandedShipments, [$shipmentId]);
    }

    public function getClientOptionsProperty(): array
    {
        return Company::query()
            ->whereNull('parent_company_id')
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id')
            ->all();
    }

    public function getCurrencyOptionsProperty(): array
    {
        if (! $this->clientId) {
            return ['USD' => 'USD'];
        }
        $codes = ProformaInvoice::query()
            ->where('company_id', $this->clientId)
            ->distinct()
            ->pluck('currency_code')
            ->filter()
            ->values()
            ->all();
        if (empty($codes)) {
            return ['USD' => 'USD'];
        }

        return array_combine($codes, $codes);
    }

    public function getStatusOptionsProperty(): array
    {
        $options = [];
        foreach (ProformaInvoiceStatus::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    #[Computed]
    public function report(): ?DealBreakdownReport
    {
        if (! $this->clientId) {
            return null;
        }
        $client = Company::find($this->clientId);
        if (! $client) {
            return null;
        }

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse($this->fromDate),
            to: CarbonImmutable::parse($this->toDate),
            presentationCurrency: $this->presentationCurrency ?? $this->resolveDefaultPresentationCurrency() ?? 'USD',
            statuses: array_map(fn (string $v) => ProformaInvoiceStatus::from($v), $this->statuses),
        );

        return app(DealBreakdownReportService::class)->build($client, $filters);
    }

    private function resolveDefaultPresentationCurrency(): ?string
    {
        $pis = ProformaInvoice::query()
            ->where('company_id', $this->clientId)
            ->whereNotNull('currency_code')
            ->orderByDesc('issue_date')
            ->limit(200)
            ->pluck('currency_code');

        if ($pis->isEmpty()) {
            return 'USD';
        }

        return $pis->countBy()->sortDesc()->keys()->first();
    }
}
