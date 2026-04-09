<?php

namespace App\Domain\Logistics\Models;

use App\Domain\Logistics\Enums\PackagingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingListItem extends Model
{
    /**
     * NOTE: `package_label` and `is_primary_package` are intentionally kept in
     * $fillable so the `PackingListItemObserver` can continue to sync multi-box
     * siblings during the PR #2–#3 transition window (reads from legacy data).
     * The UI (deleted in PR #3) no longer writes these fields. PR #4 drops the
     * entire `packing_list_items` table and these field references become dead
     * code; removed there.
     */
    protected $fillable = [
        'shipment_id',
        'shipment_item_id',
        'container_number',
        'packaging_type',
        'pallet_number',
        'carton_from',
        'carton_to',
        'description',
        'package_label',
        'is_primary_package',
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
            'is_primary_package' => 'boolean',
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

        return $this->carton_from.' - '.$this->carton_to;
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
