<?php

namespace App\Policies;

use App\Domain\TradeFairs\Models\TradeFair;
use App\Models\User;

class TradeFairPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-trade-fairs');
    }

    public function view(User $user, TradeFair $tradeFair): bool
    {
        return $user->can('view-trade-fairs');
    }

    public function create(User $user): bool
    {
        return $user->can('create-trade-fairs');
    }

    public function update(User $user, TradeFair $tradeFair): bool
    {
        return $user->can('edit-trade-fairs');
    }

    public function delete(User $user, TradeFair $tradeFair): bool
    {
        return $user->can('delete-trade-fairs');
    }

    public function restore(User $user, TradeFair $tradeFair): bool
    {
        return $user->can('delete-trade-fairs');
    }

    public function forceDelete(User $user, TradeFair $tradeFair): bool
    {
        return $user->hasRole('admin');
    }
}
