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
            'manage-roles',

            // CRM
            'view-companies',
            'create-companies',
            'edit-companies',
            'delete-companies',
            'view-contacts',
            'create-contacts',
            'edit-contacts',
            'delete-contacts',

            // Catalog
            'view-categories',
            'create-categories',
            'edit-categories',
            'delete-categories',
            'manage-categories',
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
            'confirm-proforma-invoices',
            'reopen-proforma-invoices',

            // Procurement
            'view-supplier-quotations',
            'create-supplier-quotations',
            'edit-supplier-quotations',
            'delete-supplier-quotations',
            'view-purchase-orders',
            'create-purchase-orders',
            'edit-purchase-orders',
            'delete-purchase-orders',
            'generate-purchase-orders',

            // Operations
            'view-shipments',
            'create-shipments',
            'edit-shipments',
            'delete-shipments',
            'view-additional-costs',
            'create-additional-costs',
            'edit-additional-costs',
            'delete-additional-costs',

            // Documents
            'view-documents',
            'generate-documents',
            'download-documents',

            // Financial
            'view-payments',

            // Company Expenses
            'view-company-expenses',
            'create-company-expenses',
            'edit-company-expenses',
            'delete-company-expenses',
            'create-payments',
            'edit-payments',
            'delete-payments',
            'approve-payments',
            'reject-payments',
            'waive-payments',
            'view-payment-schedule',
            'generate-payment-schedule',
            'view-costs',
            'view-margins',

            // Users
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',

            // Audit Log
            'view-audit-log',

            // Supplier Audits
            'view-supplier-audits',
            'create-supplier-audits',
            'edit-supplier-audits',
            'delete-supplier-audits',
            'conduct-supplier-audits',
            'review-supplier-audits',
            'manage-audit-categories',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }
    }
}
