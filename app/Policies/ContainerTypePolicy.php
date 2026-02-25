<?php

namespace App\Policies;

use App\Domain\Settings\Models\ContainerType;
use App\Models\User;

class ContainerTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, ContainerType $containerType): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, ContainerType $containerType): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, ContainerType $containerType): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, ContainerType $containerType): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, ContainerType $containerType): bool
    {
        return $user->can('manage-settings');
    }
}
