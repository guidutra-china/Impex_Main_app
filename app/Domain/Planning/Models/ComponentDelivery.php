<?php

namespace App\Domain\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentDelivery extends Model
{
    protected $fillable = [
        'production_schedule_component_id',
        'expected_date',
        'expected_qty',
        'received_qty',
        'received_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_date'  => 'date',
            'expected_qty'   => 'integer',
            'received_qty'   => 'integer',
            'received_date'  => 'date',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductionScheduleComponent::class, 'production_schedule_component_id');
    }

    public function isReceived(): bool
    {
        return $this->received_qty !== null;
    }
}
