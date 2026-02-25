<?php

namespace App\Policies;

use App\Domain\Settings\Models\PaymentMethod;
use App\Models\User;

class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->hasRole('admin');
    }
}
