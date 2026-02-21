<?php

namespace App\Domain\Quotations\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItemSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_item_id',
        'company_id',
        'unit_cost',
        'currency_code',
        'lead_time_days',
        'moq',
        'incoterm',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'lead_time_days' => 'integer',
            'moq' => 'integer',
            'incoterm' => Incoterm::class,
        ];
    }

    // --- Relationships ---

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // --- Accessors ---

    public function getFormattedCostAttribute(): string
    {
        return number_format($this->unit_cost / 100, 2);
    }
}
