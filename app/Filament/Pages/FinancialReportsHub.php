<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Central hub linking every financial report in one place. Several report
 * pages are intentionally hidden from navigation ($shouldRegisterNavigation
 * = false) or buried behind list-header actions — this page makes them
 * discoverable.
 */
class FinancialReportsHub extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 69;

    protected static ?string $slug = 'financial-reports';

    protected string $view = 'filament.pages.financial-reports-hub';

    public ?int $companyId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.financial_reports');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.financial_reports');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    /**
     * @return array<int, array{title: string, description: string, icon: string, url: string}>
     */
    public function getGeneralReportsProperty(): array
    {
        return [
            [
                'title' => __('financial_reports_hub.cards.receivables_summary.title'),
                'description' => __('financial_reports_hub.cards.receivables_summary.description'),
                'icon' => 'heroicon-o-arrow-down-left',
                'url' => AccountsReceivableResource::getUrl('report'),
            ],
            [
                'title' => __('financial_reports_hub.cards.payables_summary.title'),
                'description' => __('financial_reports_hub.cards.payables_summary.description'),
                'icon' => 'heroicon-o-arrow-up-right',
                'url' => AccountsPayableResource::getUrl('report'),
            ],
            [
                'title' => __('financial_reports_hub.cards.deal_breakdown.title'),
                'description' => __('financial_reports_hub.cards.deal_breakdown.description'),
                'icon' => 'heroicon-o-chart-bar-square',
                'url' => ClientDealBreakdown::getUrl(),
            ],
            [
                'title' => __('financial_reports_hub.cards.cash_flow.title'),
                'description' => __('financial_reports_hub.cards.cash_flow.description'),
                'icon' => 'heroicon-o-banknotes',
                'url' => FinancialOverview::getUrl(),
            ],
            [
                'title' => __('financial_reports_hub.cards.expenses.title'),
                'description' => __('financial_reports_hub.cards.expenses.description'),
                'icon' => 'heroicon-o-receipt-percent',
                'url' => CompanyExpenseResource::getUrl('index'),
            ],
        ];
    }

    /**
     * Company-scoped reports require a ?company= query parameter.
     *
     * @return array<int, array{title: string, description: string, icon: string, url: string}>
     */
    public function getCompanyReportsProperty(): array
    {
        if (! $this->companyId) {
            return [];
        }

        $query = '?company='.$this->companyId;

        return [
            [
                'title' => __('financial_reports_hub.cards.statement.title'),
                'description' => __('financial_reports_hub.cards.statement.description'),
                'icon' => 'heroicon-o-document-text',
                'url' => AdminStatementPreview::getUrl().$query,
            ],
            [
                'title' => __('financial_reports_hub.cards.financial_report.title'),
                'description' => __('financial_reports_hub.cards.financial_report.description'),
                'icon' => 'heroicon-o-document-chart-bar',
                'url' => AdminFinancialReport::getUrl().$query,
            ],
            [
                'title' => __('financial_reports_hub.cards.custom_report.title'),
                'description' => __('financial_reports_hub.cards.custom_report.description'),
                'icon' => 'heroicon-o-adjustments-horizontal',
                'url' => CustomFinancialReport::getUrl().$query,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getCompanyOptionsProperty(): array
    {
        return Company::query()
            ->whereNull('parent_company_id')
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id')
            ->all();
    }
}
