<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-categories');
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->can('view-categories');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-categories');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can('manage-categories');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can('manage-categories');
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $user->can('manage-categories');
    }

    public function forceDelete(User $user, Tag $tag): bool
    {
        return $user->hasRole('admin');
    }
}
