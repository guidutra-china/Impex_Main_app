<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCosting extends Model
{
    protected $table = 'product_costings';

    protected $fillable = [
        'product_id',
        'currency_id',
        'base_price',
        'bom_material_cost',
        'direct_labor_cost',
        'direct_overhead_cost',
        'total_manufacturing_cost',
        'markup_percentage',
        'calculated_selling_price',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'bom_material_cost' => 'integer',
            'direct_labor_cost' => 'integer',
            'direct_overhead_cost' => 'integer',
            'total_manufacturing_cost' => 'integer',
            'markup_percentage' => 'decimal:2',
            'calculated_selling_price' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
