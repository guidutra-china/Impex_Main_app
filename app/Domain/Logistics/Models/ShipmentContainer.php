<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentContainer extends Model
{
    protected $fillable = [
        'shipment_id',
        'label',
        'container_number',
        'type',
        'seal_number',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function pallets(): HasMany
    {
        return $this->hasMany(ShipmentPallet::class)->orderBy('sort_order')->orderBy('label');
    }

    public function cartons(): HasMany
    {
        return $this->hasMany(Carton::class)->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Cartons directly in this container (not nested inside a pallet).
     */
    public function directCartons(): HasMany
    {
        return $this->hasMany(Carton::class)
            ->whereNull('shipment_pallet_id')
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    /**
     * Sum of gross weight across all cartons (both direct and inside pallets).
     */
    public function getTotalGrossWeightAttribute(): float
    {
        return (float) $this->cartons()->sum('gross_weight');
    }

    /**
     * Count of all cartons (direct + nested in pallets).
     */
    public function getTotalCartonsCountAttribute(): int
    {
        return (int) $this->cartons()->count();
    }
}
