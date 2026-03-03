<?php

namespace App\Filament\SupplierPortal\Resources;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Pages;
use App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets\SupplierPurchaseOrderStats;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'purchase-orders';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'supplierCompany';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('supplier-portal:view-purchase-orders') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total')
                    ->label('Total')
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignRight(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('issue_date')
                    ->label('Issue Date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('expected_delivery_date')
                    ->label('Expected Delivery')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class),
            ])
            ->recordUrl(fn (PurchaseOrder $record) => Pages\ViewPurchaseOrder::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (PurchaseOrder $record) => Pages\ViewPurchaseOrder::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No purchase orders')
            ->emptyStateDescription('No purchase orders found for your company.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Purchase Order Details')
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label('Issue Date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('expected_delivery_date')
                        ->label('Expected Delivery')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('total')
                        ->label('Total Value')
                        ->getStateUsing(fn ($record) => $record->total)
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                        ->weight('bold'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Items')
                ->schema([
                    ViewEntry::make('items_table')
                        ->view('supplier-portal.infolists.po-items-table')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }

    public static function getWidgets(): array
    {
        return [
            SupplierPurchaseOrderStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.purchase_orders');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.purchase_order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.purchase_orders');
    }
}
