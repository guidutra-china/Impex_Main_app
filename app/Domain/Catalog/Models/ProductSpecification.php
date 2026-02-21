<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    protected $fillable = [
        'product_id',
        'net_weight',
        'gross_weight',
        'length',
        'width',
        'height',
        'material',
        'color',
        'finish',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'net_weight' => 'decimal:3',
            'gross_weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
