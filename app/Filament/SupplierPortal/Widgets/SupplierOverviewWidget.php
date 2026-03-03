<?php

namespace App\Filament\SupplierPortal\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }

        $companyId = $tenant->getKey();

        $activePOs = PurchaseOrder::where('supplier_company_id', $companyId)
            ->whereNotIn('status', [
                PurchaseOrderStatus::CANCELLED->value,
                PurchaseOrderStatus::COMPLETED->value,
            ])
            ->count();

        $totalPOValue = PurchaseOrder::where('supplier_company_id', $companyId)
            ->whereNotIn('status', [
                PurchaseOrderStatus::CANCELLED->value,
                PurchaseOrderStatus::DRAFT->value,
            ])
            ->get()
            ->sum(fn ($po) => $po->total);

        $totalPaid = Payment::where('company_id', $companyId)
            ->where('direction', PaymentDirection::OUTBOUND)
            ->where('status', PaymentStatus::APPROVED)
            ->sum('amount');

        return [
            Stat::make('Active Purchase Orders', $activePOs)
                ->description('In progress')
                ->icon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('Total PO Value', 'USD ' . Money::format($totalPOValue, 2))
                ->description('Confirmed orders')
                ->icon('heroicon-o-currency-dollar')
                ->color('info'),

            Stat::make('Total Payments Received', 'USD ' . Money::format($totalPaid, 2))
                ->description('Completed payments')
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}
