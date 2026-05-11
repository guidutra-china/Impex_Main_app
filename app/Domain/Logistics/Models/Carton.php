<?php

namespace App\Domain\Logistics\Models;

use App\Domain\Logistics\Enums\PackagingType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carton extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'shipment_container_id',
        'shipment_pallet_id',
        'label',
        'container_number', // DEPRECATED: legacy string, kept for backcompat; use container relation
        'pallet_number',    // DEPRECATED: legacy string, kept for backcompat; use pallet relation
        'packaging_type',
        'gross_weight',
        'net_weight',
        'length',
        'width',
        'height',
        'volume',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pallet_number' => 'integer',
            'gross_weight' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'volume' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    // Legacy data mixes 'CARTON' (uppercase, old CreateCartonAction default) and
    // 'carton' (lowercase, enum->value from auto-generation). Normalize on read
    // case-insensitively and always persist lowercase. Returns null for unknown values.
    protected function packagingType(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?PackagingType => $value === null
                ? null
                : PackagingType::tryFrom(strtolower($value)),
            set: fn (PackagingType|string|null $value): ?string => match (true) {
                $value === null => null,
                $value instanceof PackagingType => $value->value,
                default => strtolower($value),
            },
        );
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function shipmentContainer(): BelongsTo
    {
        return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id');
    }

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(ShipmentPallet::class, 'shipment_pallet_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(CartonContent::class)->orderBy('sort_order');
    }

    /**
     * Resolve the effective container for this carton:
     *   - If nested in a pallet that has a container → that container
     *     (pallet → container takes priority even if carton.shipment_container_id is set)
     *   - Else if shipment_container_id is set → that container
     *   - Else null (loose)
     */
    public function getEffectiveContainer(): ?ShipmentContainer
    {
        if ($this->shipment_pallet_id) {
            $this->loadMissing('pallet.container');
            if ($this->pallet?->container) {
                return $this->pallet->container;
            }
        }

        $this->loadMissing('shipmentContainer');

        return $this->shipmentContainer;
    }
}
