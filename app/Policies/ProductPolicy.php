<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-products');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('view-products');
    }

    public function create(User $user): bool
    {
        return $user->can('create-products');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('edit-products');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('delete-products');
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->can('delete-products');
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->hasRole('admin');
    }
}
