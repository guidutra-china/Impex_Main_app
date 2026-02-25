<?php

namespace App\Policies;

use App\Domain\Inquiries\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-inquiries');
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->can('view-inquiries');
    }

    public function create(User $user): bool
    {
        return $user->can('create-inquiries');
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $user->can('edit-inquiries');
    }

    public function delete(User $user, Inquiry $inquiry): bool
    {
        return $user->can('delete-inquiries');
    }

    public function restore(User $user, Inquiry $inquiry): bool
    {
        return $user->can('delete-inquiries');
    }

    public function forceDelete(User $user, Inquiry $inquiry): bool
    {
        return $user->hasRole('admin');
    }
}
