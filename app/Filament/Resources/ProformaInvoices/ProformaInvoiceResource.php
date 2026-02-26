<?php

namespace App\Filament\Resources\ProformaInvoices;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\RelationManagers\AdditionalCostsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProformaInvoices\Pages\CreateProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ProformaInvoices\RelationManagers\PaymentScheduleRelationManager;
use App\Filament\Resources\ProformaInvoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\ProformaInvoices\Schemas\ProformaInvoiceForm;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use App\Filament\Resources\ProformaInvoices\Widgets\ShipmentFulfillmentWidget;
use App\Filament\Resources\ProformaInvoices\Schemas\ProformaInvoiceInfolist;
use App\Filament\Resources\ProformaInvoices\Tables\ProformaInvoicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ProformaInvoiceResource extends Resource
{
    protected static ?string $model = ProformaInvoice::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'proforma-invoices';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-proforma-invoices') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return ProformaInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProformaInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProformaInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            AdditionalCostsRelationManager::class,
            PaymentScheduleRelationManager::class,
            PaymentsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            ProformaInvoiceStats::class,
            ShipmentFulfillmentWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProformaInvoices::route('/'),
            'create' => CreateProformaInvoice::route('/create'),
            'view' => ViewProformaInvoice::route('/{record}'),
            'edit' => EditProformaInvoice::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.proforma_invoices');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.proforma_invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.proforma_invoices');
    }
}
