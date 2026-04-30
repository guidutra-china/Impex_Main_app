<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ForwarderPortalRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->getForwarderPortalPermissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->createForwarderPortalRoles();
    }

    protected function getForwarderPortalPermissions(): array
    {
        return [
            'forwarder-portal:view-dashboard',
            'forwarder-portal:view-shipments',
            'forwarder-portal:view-shipment-details',
            'forwarder-portal:update-shipment-logistics',
            'forwarder-portal:view-financial-summary',

            'view-messaging',
            'send-messages',
        ];
    }

    protected function createForwarderPortalRoles(): void
    {
        $forwarderFull = Role::firstOrCreate(['name' => 'forwarder_full', 'guard_name' => 'web']);
        $forwarderFull->syncPermissions([
            'forwarder-portal:view-dashboard',
            'forwarder-portal:view-shipments',
            'forwarder-portal:view-shipment-details',
            'forwarder-portal:update-shipment-logistics',
            'forwarder-portal:view-financial-summary',
            'view-messaging',
            'send-messages',
        ]);

        $forwarderOps = Role::firstOrCreate(['name' => 'forwarder_operations', 'guard_name' => 'web']);
        $forwarderOps->syncPermissions([
            'forwarder-portal:view-dashboard',
            'forwarder-portal:view-shipments',
            'forwarder-portal:view-shipment-details',
            'forwarder-portal:update-shipment-logistics',
            'view-messaging',
            'send-messages',
        ]);
    }
}
