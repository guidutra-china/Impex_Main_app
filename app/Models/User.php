<?php

namespace App\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'job_title',
        'type',
        'company_id',
        'is_admin',
        'status',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'type' => UserType::class,
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return $this->type->isInternal() && $this->status === 'active';
        }

        // Future portal panel
        if ($panel->getId() === 'portal') {
            return $this->type->isExternal() && $this->status === 'active';
        }

        return false;
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // --- Helpers ---

    public function isInternal(): bool
    {
        return $this->type === UserType::INTERNAL;
    }

    public function isExternal(): bool
    {
        return $this->type?->isExternal() ?? false;
    }
}
