<?php

namespace App\Domain\Logistics\Models;

use App\Domain\Logistics\Enums\PackagingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingListItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'shipment_item_id',
        'packaging_type',
        'pallet_number',
        'carton_from',
        'carton_to',
        'description',
        'quantity',
        'qty_per_carton',
        'total_quantity',
        'gross_weight',
        'net_weight',
        'total_gross_weight',
        'total_net_weight',
        'length',
        'width',
        'height',
        'volume',
        'total_volume',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'packaging_type' => PackagingType::class,
            'pallet_number' => 'integer',
            'carton_from' => 'integer',
            'carton_to' => 'integer',
            'quantity' => 'integer',
            'qty_per_carton' => 'integer',
            'total_quantity' => 'integer',
            'gross_weight' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'total_gross_weight' => 'decimal:3',
            'total_net_weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'volume' => 'decimal:4',
            'total_volume' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }

    // --- Accessors ---

    public function getCartonRangeAttribute(): string
    {
        if ($this->carton_from === $this->carton_to) {
            return (string) $this->carton_from;
        }

        return $this->carton_from . ' - ' . $this->carton_to;
    }

    public function getCartonCountAttribute(): int
    {
        return $this->carton_to - $this->carton_from + 1;
    }

    public function getProductNameAttribute(): string
    {
        return $this->shipmentItem?->product_name
            ?? $this->description
            ?? '—';
    }
}
