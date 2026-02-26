<?php

namespace App\Filament\Resources\Settings\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role Information')
                ->schema([
                    TextInput::make('name')
                        ->label('Role Name')
                        ->required()
                        ->maxLength(255)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Role names are managed via code. Only permissions can be changed here.'),
                ]),

            Section::make('Permissions')
                ->description('Select the permissions for this role. Changes take effect immediately after saving.')
                ->schema([
                    CheckboxList::make('permissions')
                        ->label('')
                        ->relationship('permissions', 'name')
                        ->options(fn () => static::getGroupedOptions())
                        ->descriptions(fn () => static::getPermissionDescriptions())
                        ->bulkToggleable()
                        ->columns(3)
                        ->searchable(),
                ]),
        ]);
    }

    protected static function getGroupedOptions(): array
    {
        return Permission::where('guard_name', 'web')
            ->orderByRaw("
                CASE
                    WHEN name LIKE '%-companies' OR name LIKE '%-contacts' THEN '01'
                    WHEN name LIKE '%-categories' OR name LIKE 'manage-categories' THEN '02'
                    WHEN name LIKE '%-products' THEN '03'
                    WHEN name LIKE '%-inquiries' THEN '04'
                    WHEN name LIKE '%-supplier-quotations' THEN '05'
                    WHEN name LIKE '%-quotations' AND name NOT LIKE '%-supplier-quotations' THEN '06'
                    WHEN name LIKE '%-proforma-invoices' OR name LIKE 'confirm-proforma-invoices' OR name LIKE 'reopen-proforma-invoices' THEN '07'
                    WHEN name LIKE '%-purchase-orders' OR name LIKE 'generate-purchase-orders' THEN '08'
                    WHEN name LIKE '%-shipments' THEN '09'
                    WHEN name LIKE '%-payments' OR name LIKE '%-payment-schedule' OR name LIKE 'generate-payment-schedule' OR name LIKE 'waive-payments' THEN '10'
                    WHEN name LIKE '%-additional-costs' THEN '11'
                    WHEN name LIKE 'view-costs' OR name LIKE 'view-margins' THEN '12'
                    WHEN name LIKE '%-documents' THEN '13'
                    WHEN name LIKE '%-supplier-audits' OR name LIKE '%-audit-categories' OR name LIKE 'conduct-supplier-audits' OR name LIKE 'review-supplier-audits' THEN '14'
                    WHEN name LIKE '%-users' OR name LIKE 'manage-roles' THEN '15'
                    WHEN name LIKE '%-settings' OR name LIKE '%-audit-log' THEN '16'
                    ELSE '99'
                END,
                name
            ")
            ->pluck('name', 'id')
            ->map(fn ($name) => static::formatPermissionLabel($name))
            ->toArray();
    }

    protected static function getPermissionDescriptions(): array
    {
        $groupMap = [
            'companies' => 'CRM',
            'contacts' => 'CRM',
            'categories' => 'Catalog',
            'products' => 'Catalog',
            'inquiries' => 'Trade',
            'supplier-quotations' => 'Trade',
            'quotations' => 'Trade',
            'proforma-invoices' => 'Trade',
            'purchase-orders' => 'Trade',
            'shipments' => 'Trade',
            'payments' => 'Finance',
            'payment-schedule' => 'Finance',
            'additional-costs' => 'Finance',
            'costs' => 'Finance',
            'margins' => 'Finance',
            'documents' => 'Documents',
            'supplier-audits' => 'Audits',
            'audit-categories' => 'Audits',
            'audit-log' => 'System',
            'settings' => 'System',
            'users' => 'System',
            'roles' => 'System',
        ];

        $descriptions = [];

        $permissions = Permission::where('guard_name', 'web')->get();

        foreach ($permissions as $permission) {
            $group = 'Other';
            foreach ($groupMap as $suffix => $label) {
                if (str_ends_with($permission->name, "-{$suffix}") || str_contains($permission->name, "-{$suffix}")) {
                    $group = $label;
                    break;
                }
            }
            $descriptions[$permission->id] = $group;
        }

        return $descriptions;
    }

    protected static function formatPermissionLabel(string $permission): string
    {
        return ucwords(str_replace('-', ' ', $permission));
    }
}
