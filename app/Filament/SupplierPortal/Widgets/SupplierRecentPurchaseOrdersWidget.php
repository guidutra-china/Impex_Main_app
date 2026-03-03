<?php

namespace App\Filament\SupplierPortal\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class SupplierRecentPurchaseOrdersWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 3;

    public function getHeading(): string
    {
        return 'Recent Purchase Orders';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                PurchaseOrder::query()
                    ->where('supplier_company_id', $tenant?->getKey())
                    ->latest('issue_date')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('reference')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('issue_date')
                    ->label('Issue Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? 'USD') . ' ' . Money::format($state, 2))
                    ->alignRight(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No purchase orders')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
