<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PortalRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = $this->getPortalPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->createPortalRoles();
    }

    protected function getPortalPermissions(): array
    {
        return [
            // Portal: Shipments
            'portal:view-shipments',
            'portal:view-shipment-details',

            // Portal: Quotations
            'portal:view-quotations',
            'portal:view-quotation-details',

            // Portal: Proforma Invoices
            'portal:view-proforma-invoices',
            'portal:view-proforma-invoice-details',

            // Portal: Payments
            'portal:view-payments',

            // Portal: Documents
            'portal:download-documents',

            // Portal: Dashboard
            'portal:view-dashboard',
            'portal:view-financial-summary',
        ];
    }

    protected function createPortalRoles(): void
    {
        // Client Full — sees everything including financial data
        $clientFull = Role::firstOrCreate(['name' => 'client_full', 'guard_name' => 'web']);
        $clientFull->syncPermissions([
            'portal:view-shipments',
            'portal:view-shipment-details',
            'portal:view-quotations',
            'portal:view-quotation-details',
            'portal:view-proforma-invoices',
            'portal:view-proforma-invoice-details',
            'portal:view-payments',
            'portal:download-documents',
            'portal:view-dashboard',
            'portal:view-financial-summary',
        ]);

        // Client Operations — no financial visibility
        $clientOps = Role::firstOrCreate(['name' => 'client_operations', 'guard_name' => 'web']);
        $clientOps->syncPermissions([
            'portal:view-shipments',
            'portal:view-shipment-details',
            'portal:view-quotations',
            'portal:view-quotation-details',
            'portal:view-proforma-invoices',
            'portal:view-proforma-invoice-details',
            'portal:download-documents',
            'portal:view-dashboard',
        ]);
    }
}
