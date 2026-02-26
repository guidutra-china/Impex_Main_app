<?php

namespace App\Policies;

use App\Domain\Logistics\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-shipments') || $user->can('portal:view-shipments');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $user->can('view-shipments') || $user->can('portal:view-shipments');
    }

    public function create(User $user): bool
    {
        return $user->can('create-shipments');
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->can('edit-shipments');
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->can('delete-shipments');
    }

    public function restore(User $user, Shipment $shipment): bool
    {
        return $user->can('delete-shipments');
    }

    public function forceDelete(User $user, Shipment $shipment): bool
    {
        return $user->is_admin;
    }
}
