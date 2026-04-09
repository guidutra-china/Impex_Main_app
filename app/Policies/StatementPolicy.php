<?php

namespace App\Policies;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Enums\UserType;
use App\Models\User;

final class StatementPolicy
{
    public function view(User $user, Company $company): bool
    {
        // Internal / admin users can view any company's statement.
        if ($user->type === UserType::INTERNAL || $user->is_admin) {
            return true;
        }

        // Portal users (client/supplier) can only view their own company.
        return $user->company_id !== null
            && (int) $user->company_id === (int) $company->id;
    }
}
