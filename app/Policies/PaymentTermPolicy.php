<?php

namespace App\Policies;

use App\Domain\Settings\Models\PaymentTerm;
use App\Models\User;

class PaymentTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-settings');
    }

    public function view(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('view-settings');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-settings');
    }

    public function update(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('manage-settings');
    }

    public function delete(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('manage-settings');
    }

    public function restore(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('manage-settings');
    }

    public function forceDelete(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->hasRole('admin');
    }
}
