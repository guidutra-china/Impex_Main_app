<?php

namespace App\Domain\Catalog\Models;

use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyProduct extends Pivot
{
    protected $table = 'company_product';

    public $incrementing = true;

    protected $fillable = [
        'company_id',
        'product_id',
        'role',
        'external_code',
        'external_name',
        'unit_price',
        'currency_code',
        'lead_time_days',
        'moq',
        'notes',
        'is_preferred',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'lead_time_days' => 'integer',
            'moq' => 'integer',
            'is_preferred' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
