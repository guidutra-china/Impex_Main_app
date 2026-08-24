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
        'gross_weight',
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
            'gross_weight' => 'decimal:3',
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

    /**
     * Cubagem do conjunto paletizado, derivada das medidas do pallet (que são
     * as do conjunto, não as do estrado vazio). Null quando falta medida.
     */
    public function getVolumeAttribute(): ?float
    {
        $length = (float) $this->length;
        $width = (float) $this->width;
        $height = (float) $this->height;

        if ($length <= 0 || $width <= 0 || $height <= 0) {
            return null;
        }

        return round($length * $width * $height / 1_000_000, 6);
    }

    /**
     * Peso bruto que vale para este pallet: o pesado na balança quando existe,
     * senão a soma das caixas em cima. Zero não conta como pesagem — zeraria o
     * total do embarque em silêncio.
     */
    public function effectiveGrossWeight(float $cartonsGrossWeight): float
    {
        $own = (float) $this->gross_weight;

        return $own > 0 ? $own : $cartonsGrossWeight;
    }

    /**
     * Cubagem que vale para este pallet: a do conjunto quando as três medidas
     * estão preenchidas, senão a soma dos volumes das caixas.
     */
    public function effectiveVolume(float $cartonsVolume): float
    {
        return $this->volume ?? $cartonsVolume;
    }
}
