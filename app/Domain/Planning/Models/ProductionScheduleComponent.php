<?php

namespace App\Domain\Planning\Models;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScheduleComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_schedule_id',
        'proforma_invoice_item_id',
        'component_name',
        'status',
        'supplier_name',
        'quantity_required',
        'eta',
        'notes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status'            => ComponentStatus::class,
            'eta'               => 'date',
            'quantity_required' => 'integer',
        ];
    }

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComponentDelivery::class, 'production_schedule_component_id')
            ->orderBy('expected_date');
    }

    public function totalReceived(): int
    {
        return $this->deliveries->sum(fn ($d) => $d->received_qty ?? 0);
    }

    public function progressPercent(): int
    {
        if ($this->quantity_required <= 0) {
            return 0;
        }

        return min(100, (int) round(($this->totalReceived() / $this->quantity_required) * 100));
    }

    public function isRiskForDate(\Carbon\Carbon $date): bool
    {
        if (!$this->status->isRisk()) {
            return false;
        }
        if ($this->eta === null) {
            return true;
        }
        return $this->eta->gt($date);
    }
}
