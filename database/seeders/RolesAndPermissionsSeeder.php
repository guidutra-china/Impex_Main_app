<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = $this->getPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->createRoles($permissions);
    }

    protected function getPermissions(): array
    {
        return [
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
            'view-products',
            'create-products',
            'edit-products',
            'delete-products',
            'view-categories',
            'manage-categories',

            // Inquiries
            'view-inquiries',
            'create-inquiries',
            'edit-inquiries',
            'delete-inquiries',

            // Supplier Quotations (RFQ)
            'view-supplier-quotations',
            'create-supplier-quotations',
            'edit-supplier-quotations',
            'delete-supplier-quotations',

            // Quotations
            'view-quotations',
            'create-quotations',
            'edit-quotations',
            'delete-quotations',

            // Proforma Invoices
            'view-proforma-invoices',
            'create-proforma-invoices',
            'edit-proforma-invoices',
            'delete-proforma-invoices',
            'confirm-proforma-invoices',
            'reopen-proforma-invoices',

            // Purchase Orders
            'view-purchase-orders',
            'create-purchase-orders',
            'edit-purchase-orders',
            'delete-purchase-orders',
            'generate-purchase-orders',

            // Shipments
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
            'reject-payments',
            'waive-payments',
            'view-payment-schedule',
            'generate-payment-schedule',

            // Additional Costs
            'view-additional-costs',
            'create-additional-costs',
            'edit-additional-costs',
            'delete-additional-costs',

            // Costs & Margins (sensitive data)
            'view-costs',
            'view-margins',

            // Documents
            'view-documents',
            'generate-documents',
            'download-documents',

            // Supplier Audits
            'view-supplier-audits',
            'create-supplier-audits',
            'edit-supplier-audits',
            'delete-supplier-audits',
            'conduct-supplier-audits',
            'review-supplier-audits',
            'manage-audit-categories',

            // Audit Log
            'view-audit-log',

            // Settings
            'view-settings',
            'manage-settings',

            // Users
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-roles',
        ];
    }

    protected function createRoles(array $allPermissions): void
    {
        // Admin — full access
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($allPermissions);

        // Manager — operations + approvals, no user/role management
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->syncPermissions(array_filter($allPermissions, fn ($p) => ! in_array($p, [
            'delete-companies',
            'delete-contacts',
            'delete-products',
            'delete-inquiries',
            'delete-supplier-quotations',
            'delete-quotations',
            'delete-proforma-invoices',
            'delete-purchase-orders',
            'delete-shipments',
            'delete-payments',
            'manage-settings',
            'manage-audit-categories',
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-roles',
        ])));

        // Operator — CRUD without approvals, costs, or deletions
        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $operator->syncPermissions(array_filter($allPermissions, fn ($p) => ! in_array($p, [
            'delete-companies',
            'delete-contacts',
            'delete-products',
            'delete-inquiries',
            'delete-supplier-quotations',
            'delete-quotations',
            'delete-proforma-invoices',
            'delete-purchase-orders',
            'delete-shipments',
            'edit-payments',
            'delete-payments',
            'approve-payments',
            'reject-payments',
            'waive-payments',
            'confirm-proforma-invoices',
            'reopen-proforma-invoices',
            'view-costs',
            'view-margins',
            'manage-settings',
            'manage-audit-categories',
            'delete-supplier-audits',
            'review-supplier-audits',
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-roles',
        ])));

        // Viewer — read-only, no costs/margins
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions(array_filter($allPermissions, fn ($p) => str_starts_with($p, 'view-') && ! in_array($p, [
            'view-costs',
            'view-margins',
            'view-users',
            'view-settings',
        ])));
    }
}
