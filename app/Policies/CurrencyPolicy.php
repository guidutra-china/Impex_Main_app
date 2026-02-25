<?php

namespace App\Policies;

use App\Domain\Settings\Models\Currency;
use App\Models\User;

class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, Currency $currency): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, Currency $currency): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, Currency $currency): bool
    {
        return $user->can('manage-settings');
    }
}
