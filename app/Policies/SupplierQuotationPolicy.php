<?php

namespace App\Policies;

use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;

class SupplierQuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-supplier-quotations');
    }

    public function view(User $user, SupplierQuotation $supplierQuotation): bool
    {
        return $user->can('view-supplier-quotations');
    }

    public function create(User $user): bool
    {
        return $user->can('create-supplier-quotations');
    }

    public function update(User $user, SupplierQuotation $supplierQuotation): bool
    {
        return $user->can('edit-supplier-quotations');
    }

    public function delete(User $user, SupplierQuotation $supplierQuotation): bool
    {
        return $user->can('delete-supplier-quotations');
    }

    public function restore(User $user, SupplierQuotation $supplierQuotation): bool
    {
        return $user->can('delete-supplier-quotations');
    }

    public function forceDelete(User $user, SupplierQuotation $supplierQuotation): bool
    {
        return $user->hasRole('admin');
    }
}
