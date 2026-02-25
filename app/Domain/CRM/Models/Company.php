<?php

namespace App\Domain\CRM\Models;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

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

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    public function companyRoles(): HasMany
    {
        return $this->hasMany(CompanyRoleAssignment::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Catalog\Models\Product::class, 'company_product')
            ->using(CompanyProduct::class)
            ->withPivot([
                'id',
                'role',
                'external_code',
                'external_name',
                'external_description',
                'unit_price',
                'currency_code',
                'incoterm',
                'lead_time_days',
                'moq',
                'notes',
                'is_preferred',
                'avatar_path',
                'avatar_disk',
            ])
            ->withTimestamps();
    }

    public function supplierProducts(): BelongsToMany
    {
        return $this->products()->wherePivot('role', 'supplier');
    }

    public function clientProducts(): BelongsToMany
    {
        return $this->products()->wherePivot('role', 'client');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_company')
            ->withPivot('notes')
            ->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }

    public function supplierAudits(): HasMany
    {
        return $this->hasMany(SupplierAudit::class, 'company_id');
    }

    public function latestAudit(): HasOne
    {
        return $this->hasOne(SupplierAudit::class, 'company_id')->latestOfMany('conducted_date');
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
