<?php

namespace App\Filament\Resources\CRM\SupplierAudits;

use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\ConductAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\CreateSupplierAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\EditSupplierAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\ListSupplierAudits;
use App\Filament\Resources\CRM\SupplierAudits\Pages\ViewSupplierAudit;
use App\Filament\Resources\CRM\SupplierAudits\Schemas\SupplierAuditForm;
use App\Filament\Resources\CRM\SupplierAudits\Schemas\SupplierAuditInfolist;
use App\Filament\Resources\CRM\SupplierAudits\Tables\SupplierAuditsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class SupplierAuditResource extends Resource
{
    protected static ?string $model = SupplierAudit::class;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Supplier Audits';

    protected static ?string $slug = 'crm/supplier-audits';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-supplier-audits') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'location'];
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierAuditForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierAuditInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierAuditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierAudits::route('/'),
            'create' => CreateSupplierAudit::route('/create'),
            'view' => ViewSupplierAudit::route('/{record}'),
            'edit' => EditSupplierAudit::route('/{record}/edit'),
            'conduct' => ConductAudit::route('/{record}/conduct'),
        ];
    }
}
