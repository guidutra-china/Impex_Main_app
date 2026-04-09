<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'carton_id',
        'shipment_item_id',
        'pieces',
        'part_label',
        'multi_box_set_id',
        'weight_share',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pieces' => 'integer',
            'weight_share' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }
}
