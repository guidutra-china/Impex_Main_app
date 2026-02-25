<?php

namespace App\Policies;

use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Models\User;

class SupplierAuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-supplier-audits');
    }

    public function view(User $user, SupplierAudit $audit): bool
    {
        return $user->can('view-supplier-audits');
    }

    public function create(User $user): bool
    {
        return $user->can('create-supplier-audits');
    }

    public function update(User $user, SupplierAudit $audit): bool
    {
        return $user->can('edit-supplier-audits');
    }

    public function delete(User $user, SupplierAudit $audit): bool
    {
        return $user->can('delete-supplier-audits');
    }

    public function restore(User $user, SupplierAudit $audit): bool
    {
        return $user->can('delete-supplier-audits');
    }

    public function forceDelete(User $user, SupplierAudit $audit): bool
    {
        return $user->hasRole('admin');
    }
}
