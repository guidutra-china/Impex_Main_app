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
        'eta',
        'notes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ComponentStatus::class,
            'eta'    => 'date',
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
