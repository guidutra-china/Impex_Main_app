<?php

namespace App\Domain\Planning\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProductionSchedule extends Model
{
    use HasFactory, HasReference, LogsActivity;

    protected $fillable = [
        'proforma_invoice_id',
        'purchase_order_id',
        'supplier_company_id',
        'reference',
        'received_date',
        'version',
        'notes',
        'created_by',
        'status',
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'version' => 'integer',
            'status' => \App\Domain\Planning\Enums\ProductionScheduleStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    // --- Activity Log ---

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('production_schedule')
            ->setDescriptionForEvent(fn (string $eventName) => "Production Schedule {$this->reference} was {$eventName}");
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::PRODUCTION_SCHEDULE;
    }

    // --- Relationships ---

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ProductionScheduleEntry::class)->orderBy('production_date');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductionScheduleComponent::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // --- Accessors ---

    public function getTotalQuantityAttribute(): int
    {
        return $this->entries->sum('quantity');
    }

    public function getTotalActualQuantityAttribute(): int
    {
        return $this->entries->sum(fn ($e) => $e->actual_quantity ?? 0);
    }

    public function getShipmentReadyQuantityByItem(): \Illuminate\Support\Collection
    {
        return $this->entries
            ->groupBy('proforma_invoice_item_id')
            ->map(fn ($entries) => $entries->sum(fn ($e) => $e->actual_quantity ?? 0));
    }

    public function getProductionDatesAttribute(): array
    {
        return $this->entries
            ->pluck('production_date')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function getQuantityReadyByDate(\Carbon\Carbon $date): int
    {
        return $this->entries
            ->where('production_date', '<=', $date)
            ->sum('quantity');
    }

    // --- Component Risk ---

    public function componentRiskDates(): array
    {
        $risk = [];
        $this->components->each(function (ProductionScheduleComponent $component) use (&$risk) {
            $key = 'item-' . $component->proforma_invoice_item_id;
            foreach ($this->entries as $entry) {
                if ($entry->proforma_invoice_item_id !== $component->proforma_invoice_item_id) {
                    continue;
                }
                if ($component->isRiskForDate($entry->production_date)) {
                    $risk[$key][] = $entry->production_date->format('Y-m-d');
                }
            }
        });
        foreach ($risk as $key => $dates) {
            $risk[$key] = array_values(array_unique($dates));
        }
        return $risk;
    }

    public function hasComponentRisk(): bool
    {
        return !empty($this->componentRiskDates());
    }
}
