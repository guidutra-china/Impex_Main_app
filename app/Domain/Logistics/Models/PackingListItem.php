<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingListItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'carton_number',
        'shipment_item_id',
        'description',
        'quantity',
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
            'quantity' => 'integer',
            'gross_weight' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'volume' => 'decimal:4',
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

    public function getCalculatedVolumeAttribute(): ?float
    {
        if ($this->length && $this->width && $this->height) {
            return round(($this->length * $this->width * $this->height) / 1000000, 4);
        }

        return null;
    }

    public function getProductNameAttribute(): string
    {
        return $this->shipmentItem?->product_name
            ?? $this->description
            ?? '—';
    }
}
