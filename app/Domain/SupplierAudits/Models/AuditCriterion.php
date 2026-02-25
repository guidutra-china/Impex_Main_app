<?php

namespace App\Domain\SupplierAudits\Models;

use App\Domain\SupplierAudits\Enums\CriterionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditCriterion extends Model
{
    protected $table = 'audit_criteria';

    protected $fillable = [
        'audit_category_id',
        'name',
        'description',
        'type',
        'is_critical',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CriterionType::class,
            'is_critical' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AuditCategory::class, 'audit_category_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AuditResponse::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
