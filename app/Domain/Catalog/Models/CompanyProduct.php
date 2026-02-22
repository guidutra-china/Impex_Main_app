<?php

namespace App\Domain\Catalog\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
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
        'external_description',
        'unit_price',
        'currency_code',
        'incoterm',
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
            'incoterm' => Incoterm::class,
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
