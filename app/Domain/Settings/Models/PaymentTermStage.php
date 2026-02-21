<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTermStage extends Model
{
    protected $fillable = [
        'payment_term_id',
        'percentage',
        'days',
        'calculation_base',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'days' => 'integer',
            'calculation_base' => CalculationBase::class,
            'sort_order' => 'integer',
        ];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }
}
