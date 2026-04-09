<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentPallet extends Model
{
    protected $fillable = [
        'shipment_id',
        'shipment_container_id',
        'label',
        'pallet_number',
        'length',
        'width',
        'height',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pallet_number' => 'integer',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id');
    }

    public function cartons(): HasMany
    {
        return $this->hasMany(Carton::class, 'shipment_pallet_id')
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    /**
     * Sum of carton gross weights on this pallet.
     */
    public function getTotalGrossWeightAttribute(): float
    {
        return (float) $this->cartons()->sum('gross_weight');
    }

    public function getCartonsCountAttribute(): int
    {
        return (int) $this->cartons()->count();
    }
}
