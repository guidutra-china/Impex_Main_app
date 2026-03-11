<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SupplierPortalRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = $this->getSupplierPortalPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->createSupplierPortalRoles();
    }

    protected function getSupplierPortalPermissions(): array
    {
        return [
            // Supplier Portal: Purchase Orders
            'supplier-portal:view-purchase-orders',
            'supplier-portal:view-purchase-order-details',

            // Supplier Portal: Shipments
            'supplier-portal:view-shipments',
            'supplier-portal:view-shipment-details',

            // Supplier Portal: Payments
            'supplier-portal:view-payments',

            // Supplier Portal: Products
            'supplier-portal:view-products',

            // Supplier Portal: Financial
            'supplier-portal:view-financial-summary',

            // Supplier Portal: Dashboard
            'supplier-portal:view-dashboard',

            // Supplier Portal: Production Schedules
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ];
    }

    protected function createSupplierPortalRoles(): void
    {
        // Supplier Full — sees everything
        $supplierFull = Role::firstOrCreate(['name' => 'supplier_full', 'guard_name' => 'web']);
        $supplierFull->syncPermissions([
            'supplier-portal:view-products',
            'supplier-portal:view-financial-summary',
            'supplier-portal:view-purchase-orders',
            'supplier-portal:view-purchase-order-details',
            'supplier-portal:view-shipments',
            'supplier-portal:view-shipment-details',
            'supplier-portal:view-payments',
            'supplier-portal:view-dashboard',
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ]);

        // Supplier Operations — no payment visibility
        $supplierOps = Role::firstOrCreate(['name' => 'supplier_operations', 'guard_name' => 'web']);
        $supplierOps->syncPermissions([
            'supplier-portal:view-products',
            'supplier-portal:view-purchase-orders',
            'supplier-portal:view-purchase-order-details',
            'supplier-portal:view-shipments',
            'supplier-portal:view-shipment-details',
            'supplier-portal:view-dashboard',
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ]);
    }
}
