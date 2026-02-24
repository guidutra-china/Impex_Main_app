<?php

namespace App\Policies;

use App\Domain\Financial\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-payments');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('view-payments');
    }

    public function create(User $user): bool
    {
        return $user->can('create-payments');
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->can('edit-payments');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->can('delete-payments');
    }

    public function restore(User $user, Payment $payment): bool
    {
        return $user->can('delete-payments');
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        return $user->is_admin;
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $user->can('approve-payments');
    }
}
