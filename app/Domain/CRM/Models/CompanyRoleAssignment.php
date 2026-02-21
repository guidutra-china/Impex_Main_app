<?php

namespace App\Domain\CRM\Models;

use App\Domain\CRM\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyRoleAssignment extends Model
{
    protected $table = 'company_roles';

    protected $fillable = [
        'company_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
