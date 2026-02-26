<?php

namespace App\Policies;

use App\Domain\Quotations\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-quotations') || $user->can('portal:view-quotations');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->can('view-quotations') || $user->can('portal:view-quotations');
    }

    public function create(User $user): bool
    {
        return $user->can('create-quotations');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('edit-quotations');
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $user->can('delete-quotations');
    }

    public function restore(User $user, Quotation $quotation): bool
    {
        return $user->can('delete-quotations');
    }

    public function forceDelete(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('admin');
    }
}
