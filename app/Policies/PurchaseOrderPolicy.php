<?php

namespace App\Policies;

use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-purchase-orders');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('view-purchase-orders');
    }

    public function create(User $user): bool
    {
        return $user->can('create-purchase-orders');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('edit-purchase-orders');
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('delete-purchase-orders');
    }

    public function restore(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('delete-purchase-orders');
    }

    public function forceDelete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasRole('admin');
    }
}
