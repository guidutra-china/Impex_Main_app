<?php

namespace App\Domain\SupplierAudits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditCategory extends Model
{
    protected $fillable = [
        'name',
        'description',
        'weight',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AuditCriterion::class)->orderBy('sort_order');
    }

    public function activeCriteria(): HasMany
    {
        return $this->criteria()->where('is_active', true);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AuditDocument::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
