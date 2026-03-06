<?php

namespace App\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
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

        if ($panel->getId() === 'portal') {
            return $this->type === UserType::CLIENT
                && $this->status === 'active'
                && $this->company_id !== null;
        }

        if ($panel->getId() === 'supplier-portal') {
            return $this->type === UserType::SUPPLIER
                && $this->status === 'active'
                && $this->company_id !== null;
        }

        // Fair panel: internal (staff) users only
        if ($panel->getId() === 'fair') {
            return $this->type === UserType::INTERNAL
                && $this->status === 'active';
        }

        return false;
    }

    // --- Tenancy (Filament) ---

    public function getTenants(Panel $panel): array|Collection
    {
        if (in_array($panel->getId(), ['portal', 'supplier-portal'])) {
            return Company::where('id', $this->company_id)->get();
        }

        return collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->company_id === $tenant->getKey();
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
