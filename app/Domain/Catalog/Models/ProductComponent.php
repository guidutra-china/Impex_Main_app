<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductComponent extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'quantity_required',
        'unit',
        'default_supplier_name',
        'lead_time_days',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:2',
            'lead_time_days'   => 'integer',
            'sort_order'       => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
