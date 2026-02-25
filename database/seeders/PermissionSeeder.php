<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Settings
            'view-settings',
            'manage-settings',

            // CRM
            'view-companies',
            'create-companies',
            'edit-companies',
            'delete-companies',

            // Catalog
            'view-categories',
            'create-categories',
            'edit-categories',
            'delete-categories',
            'view-products',
            'create-products',
            'edit-products',
            'delete-products',

            // Sales
            'view-inquiries',
            'create-inquiries',
            'edit-inquiries',
            'delete-inquiries',
            'view-quotations',
            'create-quotations',
            'edit-quotations',
            'delete-quotations',
            'view-proforma-invoices',
            'create-proforma-invoices',
            'edit-proforma-invoices',
            'delete-proforma-invoices',

            // Procurement
            'view-supplier-quotations',
            'create-supplier-quotations',
            'edit-supplier-quotations',
            'delete-supplier-quotations',
            'view-purchase-orders',
            'create-purchase-orders',
            'edit-purchase-orders',
            'delete-purchase-orders',

            // Operations
            'view-shipments',
            'create-shipments',
            'edit-shipments',
            'delete-shipments',

            // Financial
            'view-payments',
            'create-payments',
            'edit-payments',
            'delete-payments',
            'approve-payments',

            // Users
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',

            // Audit
            'view-audit-log',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }
    }
}
