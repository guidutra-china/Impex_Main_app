<?php

namespace App\Policies;

use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Models\User;

class AuditCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, AuditCategory $category): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-audit-categories');
    }

    public function update(User $user, AuditCategory $category): bool
    {
        return $user->can('manage-audit-categories');
    }

    public function delete(User $user, AuditCategory $category): bool
    {
        return $user->can('manage-audit-categories');
    }

    public function restore(User $user, AuditCategory $category): bool
    {
        return $user->can('manage-audit-categories');
    }

    public function forceDelete(User $user, AuditCategory $category): bool
    {
        return $user->hasRole('admin');
    }
}
