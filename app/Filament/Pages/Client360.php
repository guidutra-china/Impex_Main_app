<?php

namespace App\Filament\Pages;

use App\Domain\CRM\DataTransferObjects\Client360FinancialSummary;
use App\Domain\CRM\DataTransferObjects\Client360KpiSummary;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Services\Client360DataService;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Client 360 — consolidated read-only dashboard for a single client.
 *
 * Branch consolidation: when the selected company is a matrix, all sections
 * automatically include data attached to its branches.
 */
class Client360 extends Page
{
    protected string $view = 'filament.pages.client360';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'client-360';

    /** Reactive — bound to the searchable select in the Blade view. */
    public ?int $clientId = null;

    /** Query string sync so deep links work (`?client=123`). */
    protected $queryString = ['clientId' => ['as' => 'client']];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.trade');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.client_360');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.client_360');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-client-360') ?? false;
    }

    /** Searchable client dropdown options keyed by company id. */
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

    public function getClientProperty(): ?Company
    {
        if ($this->clientId === null) {
            return null;
        }

        return Company::query()
            ->with(['branches'])
            ->find($this->clientId);
    }

    public function getServiceProperty(): ?Client360DataService
    {
        return $this->client ? new Client360DataService($this->client) : null;
    }

    public function getKpisProperty(): ?Client360KpiSummary
    {
        return $this->service?->kpis();
    }

    public function getFinancialSummaryProperty(): ?Client360FinancialSummary
    {
        return $this->service?->financialSummary();
    }

    public function getTimelineProperty(): Collection
    {
        return $this->service?->timeline(30) ?? collect();
    }

    public function getInquiriesProperty(): Collection
    {
        return $this->service?->inquiries() ?? collect();
    }

    public function getInvoicesProperty(): Collection
    {
        return $this->service?->proformaInvoices() ?? collect();
    }

    public function getPurchaseOrdersProperty(): Collection
    {
        return $this->service?->purchaseOrders() ?? collect();
    }

    public function getShipmentsProperty(): Collection
    {
        return $this->service?->shipments() ?? collect();
    }

    public function getRecentPaymentsProperty(): Collection
    {
        return $this->service?->recentPayments(20) ?? collect();
    }

    public function getBranchesCountProperty(): int
    {
        return $this->client?->branches?->count() ?? 0;
    }

    /** Drill-down URLs — reuse existing Filament resources, no duplication. */
    public function inquiryUrl(int $id): string
    {
        return InquiryResource::getUrl('view', ['record' => $id]);
    }

    public function invoiceUrl(int $id): string
    {
        return ProformaInvoiceResource::getUrl('view', ['record' => $id]);
    }

    public function purchaseOrderUrl(int $id): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $id]);
    }

    public function shipmentUrl(int $id): string
    {
        return ShipmentResource::getUrl('view', ['record' => $id]);
    }

    public function paymentUrl(int $id): string
    {
        return PaymentResource::getUrl('view', ['record' => $id]);
    }

    public function companyUrl(int $id): string
    {
        return CompanyResource::getUrl('view', ['record' => $id]);
    }

    /** Format minor units to a human string with currency code. */
    public function formatMoney(int $minor, string $currency): string
    {
        return $currency . ' ' . number_format($minor / 10000, 2, '.', ',');
    }

    /**
     * Sum a shipment's freight additional costs (already eager-loaded and filtered)
     * grouped by currency. Used by the shipping section to render the freight column.
     *
     * @return array<string, int> currency_code => sum in minor units
     */
    public function shipmentFreightByCurrency(\App\Domain\Logistics\Models\Shipment $shipment): array
    {
        $result = [];
        foreach ($shipment->additionalCosts as $cost) {
            $currency = $cost->currency_code ?: 'USD';
            $result[$currency] = ($result[$currency] ?? 0) + (int) $cost->amount;
        }

        return $result;
    }
}
