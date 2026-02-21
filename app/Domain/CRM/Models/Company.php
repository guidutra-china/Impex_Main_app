<?php

namespace App\Domain\CRM\Models;

use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_number',
        'website',
        'phone',
        'email',
        'address_street',
        'address_number',
        'address_complement',
        'address_city',
        'address_state',
        'address_zip',
        'address_country',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    // --- Relationships ---

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function companyRoles(): HasMany
    {
        return $this->hasMany(CompanyRoleAssignment::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Catalog\Models\Product::class, 'company_product')
            ->withPivot([
                'role',
                'external_code',
                'external_name',
                'unit_price',
                'currency_code',
                'lead_time_days',
                'moq',
                'notes',
                'is_preferred',
            ])
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_company')
            ->withPivot('notes')
            ->withTimestamps();
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', CompanyStatus::ACTIVE);
    }

    public function scopeWithRole($query, CompanyRole $role)
    {
        return $query->whereHas('companyRoles', fn ($q) => $q->where('role', $role));
    }

    // --- Accessors ---

    public function getRolesListAttribute(): string
    {
        return $this->companyRoles
            ->pluck('role')
            ->map(fn (CompanyRole $role) => $role->getLabel())
            ->implode(', ');
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_street,
            $this->address_number,
            $this->address_complement,
            $this->address_city,
            $this->address_state,
            $this->address_zip,
            $this->address_country,
        ]);

        return implode(', ', $parts);
    }

    public function getPrimaryContactAttribute(): ?Contact
    {
        return $this->contacts->firstWhere('is_primary', true);
    }

    // --- Helper Methods ---

    public function hasRole(CompanyRole $role): bool
    {
        return $this->companyRoles->contains('role', $role);
    }

    public function isClient(): bool
    {
        return $this->hasRole(CompanyRole::CLIENT);
    }

    public function isSupplier(): bool
    {
        return $this->hasRole(CompanyRole::SUPPLIER);
    }
}
