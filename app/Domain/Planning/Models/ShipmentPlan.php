<?php

namespace App\Domain\Planning\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Traits\HasPaymentSchedule;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ShipmentPlan extends Model
{
    use HasReference, HasStateMachine, HasDocuments, HasPaymentSchedule, LogsActivity;

    protected $fillable = [
        'supplier_company_id',
        'shipment_id',
        'reference',
        'status',
        'planned_shipment_date',
        'planned_eta',
        'currency_code',
        'container_constraints',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentPlanStatus::class,
            'planned_shipment_date' => 'date',
            'planned_eta' => 'date',
            'container_constraints' => 'array',
        ];
    }

    // --- Activity Log ---

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('shipment_plan')
            ->setDescriptionForEvent(fn (string $eventName) => "Shipment Plan {$this->reference} was {$eventName}");
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::SHIPMENT_PLAN;
    }

    // --- HasStateMachine ---

    public function getStatusField(): string
    {
        return 'status';
    }

    public function getStatusEnum(): string
    {
        return ShipmentPlanStatus::class;
    }

    public static function allowedTransitions(): array
    {
        return [
            ShipmentPlanStatus::DRAFT->value => [
                ShipmentPlanStatus::CONFIRMED->value,
                ShipmentPlanStatus::CANCELLED->value,
            ],
            ShipmentPlanStatus::CONFIRMED->value => [
                ShipmentPlanStatus::SHIPPED->value,
                ShipmentPlanStatus::DRAFT->value,
                ShipmentPlanStatus::CANCELLED->value,
            ],
            ShipmentPlanStatus::SHIPPED->value => [],
            ShipmentPlanStatus::CANCELLED->value => [],
        ];
    }

    // --- Relationships ---

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentPlanItem::class)->orderBy('sort_order');
    }

    public function linkedPaymentScheduleItems(): HasMany
    {
        return $this->hasMany(PaymentScheduleItem::class, 'shipment_plan_id');
    }

    // --- Accessors ---

    public function getTotalValueAttribute(): int
    {
        return $this->items->sum('line_total');
    }

    public function getTotalQuantityAttribute(): int
    {
        return $this->items->sum('quantity');
    }

    public function getProformaInvoiceReferencesAttribute(): string
    {
        return $this->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');
    }

    // --- Business Logic ---

    public function canBeExecuted(): bool
    {
        $blockingItems = $this->linkedPaymentScheduleItems()
            ->where('is_blocking', true)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->count();

        return $blockingItems === 0;
    }

    public function hasBlockingPayments(): bool
    {
        return ! $this->canBeExecuted();
    }

    public function getBlockingPaymentLabelsAttribute(): array
    {
        return $this->linkedPaymentScheduleItems()
            ->where('is_blocking', true)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->pluck('label')
            ->all();
    }

    public function getItemsByProformaInvoice(): \Illuminate\Support\Collection
    {
        return $this->items->groupBy(
            fn (ShipmentPlanItem $item) => $item->proformaInvoiceItem->proforma_invoice_id
        );
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (ShipmentPlan $plan) {
            if (empty($plan->created_by)) {
                $plan->created_by = auth()->id();
            }
        });
    }
}
