<?php

namespace App\Policies;

use App\Domain\CRM\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-companies');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('view-companies');
    }

    public function create(User $user): bool
    {
        return $user->can('create-companies');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('edit-companies');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('delete-companies');
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->can('delete-companies');
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->hasRole('admin');
    }
}
