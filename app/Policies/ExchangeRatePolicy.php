<?php

namespace App\Policies;

use App\Domain\Settings\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasRole('admin');
    }
}
