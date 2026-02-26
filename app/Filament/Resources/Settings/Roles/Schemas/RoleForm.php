<?php

namespace App\Filament\Resources\Settings\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ...static::buildPermissionSections(),
        ]);
    }

    protected static function buildPermissionSections(): array
    {
        $groups = static::getPermissionGroups();
        $sections = [];

        foreach ($groups as $group) {
            $permissionIds = Permission::where('guard_name', 'web')
                ->whereIn('name', $group['permissions'])
                ->orderByRaw('FIELD(name, ' . collect($group['permissions'])->map(fn ($p) => "'{$p}'")->join(',') . ')')
                ->pluck('name', 'id')
                ->toArray();

            if (empty($permissionIds)) {
                continue;
            }

            $sections[] = Section::make($group['label'])
                ->icon($group['icon'])
                ->description($group['description'])
                ->collapsible()
                ->collapsed()
                ->schema([
                    CheckboxList::make('permissions_' . $group['key'])
                        ->label('')
                        ->relationship('permissions', 'name')
                        ->options(
                            collect($permissionIds)->map(fn ($name) => static::formatLabel($name))->toArray()
                        )
                        ->bulkToggleable()
                        ->columns(2),
                ]);
        }

        return $sections;
    }

    protected static function getPermissionGroups(): array
    {
        return [
            [
                'key' => 'crm_companies',
                'label' => 'CRM — Companies',
                'icon' => 'heroicon-o-building-office-2',
                'description' => 'Manage companies (clients, suppliers, factories)',
                'permissions' => [
                    'view-companies',
                    'create-companies',
                    'edit-companies',
                    'delete-companies',
                ],
            ],
            [
                'key' => 'crm_contacts',
                'label' => 'CRM — Contacts',
                'icon' => 'heroicon-o-user-group',
                'description' => 'Manage company contacts',
                'permissions' => [
                    'view-contacts',
                    'create-contacts',
                    'edit-contacts',
                    'delete-contacts',
                ],
            ],
            [
                'key' => 'catalog_categories',
                'label' => 'Catalog — Categories',
                'icon' => 'heroicon-o-tag',
                'description' => 'Manage product categories and attributes',
                'permissions' => [
                    'view-categories',
                    'create-categories',
                    'edit-categories',
                    'delete-categories',
                    'manage-categories',
                ],
            ],
            [
                'key' => 'catalog_products',
                'label' => 'Catalog — Products',
                'icon' => 'heroicon-o-cube',
                'description' => 'Manage products and variants',
                'permissions' => [
                    'view-products',
                    'create-products',
                    'edit-products',
                    'delete-products',
                ],
            ],
            [
                'key' => 'trade_inquiries',
                'label' => 'Trade — Inquiries',
                'icon' => 'heroicon-o-magnifying-glass',
                'description' => 'Manage client inquiries',
                'permissions' => [
                    'view-inquiries',
                    'create-inquiries',
                    'edit-inquiries',
                    'delete-inquiries',
                ],
            ],
            [
                'key' => 'trade_supplier_quotations',
                'label' => 'Trade — Supplier Quotations (RFQ)',
                'icon' => 'heroicon-o-document-magnifying-glass',
                'description' => 'Manage supplier quotation requests',
                'permissions' => [
                    'view-supplier-quotations',
                    'create-supplier-quotations',
                    'edit-supplier-quotations',
                    'delete-supplier-quotations',
                ],
            ],
            [
                'key' => 'trade_quotations',
                'label' => 'Trade — Quotations',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Manage client quotations',
                'permissions' => [
                    'view-quotations',
                    'create-quotations',
                    'edit-quotations',
                    'delete-quotations',
                ],
            ],
            [
                'key' => 'trade_pi',
                'label' => 'Trade — Proforma Invoices',
                'icon' => 'heroicon-o-document-check',
                'description' => 'Manage proforma invoices and their lifecycle',
                'permissions' => [
                    'view-proforma-invoices',
                    'create-proforma-invoices',
                    'edit-proforma-invoices',
                    'delete-proforma-invoices',
                    'confirm-proforma-invoices',
                    'reopen-proforma-invoices',
                ],
            ],
            [
                'key' => 'trade_po',
                'label' => 'Trade — Purchase Orders',
                'icon' => 'heroicon-o-shopping-cart',
                'description' => 'Manage purchase orders',
                'permissions' => [
                    'view-purchase-orders',
                    'create-purchase-orders',
                    'edit-purchase-orders',
                    'delete-purchase-orders',
                    'generate-purchase-orders',
                ],
            ],
            [
                'key' => 'trade_shipments',
                'label' => 'Trade — Shipments',
                'icon' => 'heroicon-o-truck',
                'description' => 'Manage shipments and packing lists',
                'permissions' => [
                    'view-shipments',
                    'create-shipments',
                    'edit-shipments',
                    'delete-shipments',
                ],
            ],
            [
                'key' => 'finance_payments',
                'label' => 'Finance — Payments',
                'icon' => 'heroicon-o-banknotes',
                'description' => 'Manage payments, approvals, and payment schedule',
                'permissions' => [
                    'view-payments',
                    'create-payments',
                    'edit-payments',
                    'delete-payments',
                    'approve-payments',
                    'reject-payments',
                    'waive-payments',
                    'view-payment-schedule',
                    'generate-payment-schedule',
                ],
            ],
            [
                'key' => 'finance_costs',
                'label' => 'Finance — Additional Costs & Margins',
                'icon' => 'heroicon-o-calculator',
                'description' => 'Manage additional costs and view sensitive financial data',
                'permissions' => [
                    'view-additional-costs',
                    'create-additional-costs',
                    'edit-additional-costs',
                    'delete-additional-costs',
                    'view-costs',
                    'view-margins',
                ],
            ],
            [
                'key' => 'documents',
                'label' => 'Documents',
                'icon' => 'heroicon-o-document-arrow-down',
                'description' => 'Generate, download, and view documents (PDF)',
                'permissions' => [
                    'view-documents',
                    'generate-documents',
                    'download-documents',
                ],
            ],
            [
                'key' => 'supplier_audits',
                'label' => 'Supplier Audits',
                'icon' => 'heroicon-o-clipboard-document-check',
                'description' => 'Manage supplier audit process',
                'permissions' => [
                    'view-supplier-audits',
                    'create-supplier-audits',
                    'edit-supplier-audits',
                    'delete-supplier-audits',
                    'conduct-supplier-audits',
                    'review-supplier-audits',
                    'manage-audit-categories',
                ],
            ],
            [
                'key' => 'system',
                'label' => 'System & Administration',
                'icon' => 'heroicon-o-cog-6-tooth',
                'description' => 'User management, roles, settings, and audit log',
                'permissions' => [
                    'view-users',
                    'create-users',
                    'edit-users',
                    'delete-users',
                    'manage-roles',
                    'view-settings',
                    'manage-settings',
                    'view-audit-log',
                ],
            ],
        ];
    }

    protected static function formatLabel(string $permission): string
    {
        return ucwords(str_replace('-', ' ', $permission));
    }
}
