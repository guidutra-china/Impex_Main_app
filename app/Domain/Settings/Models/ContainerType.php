<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'length_ft',
        'width_ft',
        'height_ft',
        'max_weight_kg',
        'cubic_capacity_cbm',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'length_ft' => 'decimal:2',
            'width_ft' => 'decimal:2',
            'height_ft' => 'decimal:2',
            'max_weight_kg' => 'decimal:2',
            'cubic_capacity_cbm' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
