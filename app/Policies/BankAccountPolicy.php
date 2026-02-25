<?php

namespace App\Policies;

use App\Domain\Settings\Models\BankAccount;
use App\Models\User;

class BankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, BankAccount $bankAccount): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, BankAccount $bankAccount): bool
    {
        return $user->can('manage-settings');
    }
}
