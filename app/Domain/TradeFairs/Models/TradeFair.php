<?php

namespace App\Domain\TradeFairs\Models;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TradeFair extends Model
{
    protected $fillable = [
        'name',
        'location',
        'start_date',
        'end_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    // --- Relationships ---

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function companyProducts(): HasManyThrough
    {
        return $this->hasManyThrough(
            CompanyProduct::class,
            Company::class,
            'trade_fair_id',
            'company_id',
            'id',
            'id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // --- Accessors ---

    public function getDisplayNameAttribute(): string
    {
        $name = $this->name;

        if ($this->location) {
            $name .= ' — '.$this->location;
        }

        if ($this->start_date) {
            $name .= ' ('.$this->start_date->format('M Y').')';
        }

        return $name;
    }
}
