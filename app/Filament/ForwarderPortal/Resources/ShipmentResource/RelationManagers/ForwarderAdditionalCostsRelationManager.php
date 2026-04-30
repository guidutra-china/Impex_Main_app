<?php

namespace App\Filament\ForwarderPortal\Resources\ShipmentResource\RelationManagers;

use App\Domain\Financial\Models\AdditionalCost;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ForwarderAdditionalCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'additionalCosts';

    protected static ?string $title = 'Forwarder Charges';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('forwarder-portal:view-financial-summary') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $tenant = Filament::getTenant();
                if ($tenant) {
                    $query->where('forwarder_company_id', $tenant->getKey());
                }
            })
            ->columns([
                TextColumn::make('description')
                    ->label(__('forwarder_portal.charges.description'))
                    ->wrap(),
                TextColumn::make('cost_type')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('forwarder_amount')
                    ->label(__('forwarder_portal.charges.amount'))
                    ->money(fn (AdditionalCost $r) => $r->forwarder_currency_code ?? 'USD', divideBy: 10000),
                TextColumn::make('forwarder_currency_code')
                    ->label(__('forwarder_portal.charges.currency'))
                    ->badge(),
                TextColumn::make('cost_date')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([])
            ->emptyStateHeading(__('forwarder_portal.charges.empty_heading'))
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
