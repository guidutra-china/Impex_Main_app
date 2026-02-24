<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Logistics\Enums\PackagingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackaging extends Model
{
    protected $table = 'product_packagings';

    protected $fillable = [
        'product_id',
        'packaging_type',
        'pcs_per_inner_box',
        'inner_box_length',
        'inner_box_width',
        'inner_box_height',
        'inner_box_weight',
        'pcs_per_carton',
        'inner_boxes_per_carton',
        'carton_length',
        'carton_width',
        'carton_height',
        'carton_weight',
        'carton_net_weight',
        'carton_cbm',
        'cartons_per_20ft',
        'cartons_per_40ft',
        'cartons_per_40hq',
        'packing_notes',
    ];

    protected function casts(): array
    {
        return [
            'packaging_type' => PackagingType::class,
            'pcs_per_inner_box' => 'integer',
            'inner_box_length' => 'decimal:2',
            'inner_box_width' => 'decimal:2',
            'inner_box_height' => 'decimal:2',
            'inner_box_weight' => 'decimal:3',
            'pcs_per_carton' => 'integer',
            'inner_boxes_per_carton' => 'integer',
            'carton_length' => 'decimal:2',
            'carton_width' => 'decimal:2',
            'carton_height' => 'decimal:2',
            'carton_weight' => 'decimal:3',
            'carton_net_weight' => 'decimal:3',
            'carton_cbm' => 'decimal:4',
            'cartons_per_20ft' => 'integer',
            'cartons_per_40ft' => 'integer',
            'cartons_per_40hq' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
