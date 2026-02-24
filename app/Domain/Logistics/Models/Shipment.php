<?php

namespace App\Domain\Logistics\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Traits\HasAdditionalCosts;
use App\Domain\Financial\Traits\HasPaymentSchedule;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use HasFactory, SoftDeletes, HasReference, HasStateMachine, HasDocuments, HasAdditionalCosts, HasPaymentSchedule;

    protected $fillable = [
        'reference',
        'issue_date',
        'company_id',
        'status',
        'transport_mode',
        'container_type',
        'currency_code',
        'carrier',
        'freight_forwarder',
        'booking_number',
        'bl_number',
        'container_number',
        'vessel_name',
        'voyage_number',
        'origin_port',
        'destination_port',
        'etd',
        'eta',
        'actual_departure',
        'actual_arrival',
        'total_gross_weight',
        'total_net_weight',
        'total_volume',
        'total_packages',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'transport_mode' => TransportMode::class,

            'issue_date' => 'date',
            'etd' => 'date',
            'eta' => 'date',
            'actual_departure' => 'date',
            'actual_arrival' => 'date',
            'total_gross_weight' => 'decimal:3',
            'total_net_weight' => 'decimal:3',
            'total_volume' => 'decimal:4',
            'total_packages' => 'integer',
        ];
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::SHIPMENT;
    }

    // --- HasStateMachine ---

    public function getStatusField(): string
    {
        return 'status';
    }

    public function getStatusEnum(): string
    {
        return ShipmentStatus::class;
    }

    public static function allowedTransitions(): array
    {
        return [
            ShipmentStatus::DRAFT->value => [ShipmentStatus::BOOKED->value, ShipmentStatus::CANCELLED->value],
            ShipmentStatus::BOOKED->value => [ShipmentStatus::CUSTOMS->value, ShipmentStatus::CANCELLED->value],
            ShipmentStatus::CUSTOMS->value => [ShipmentStatus::IN_TRANSIT->value, ShipmentStatus::CANCELLED->value],
            ShipmentStatus::IN_TRANSIT->value => [ShipmentStatus::ARRIVED->value],
            ShipmentStatus::ARRIVED->value => [],
            ShipmentStatus::CANCELLED->value => [],
        ];
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class)->orderBy('sort_order');
    }

    public function packingListItems(): HasMany
    {
        return $this->hasMany(PackingListItem::class)->orderBy('sort_order');
    }

    // --- Accessors ---

    public function getTotalValueAttribute(): int
    {
        return $this->items->sum(function ($item) {
            $piItem = $item->proformaInvoiceItem;
            return $piItem ? $piItem->unit_price * $item->quantity : 0;
        });
    }

    public function getTotalItemsCountAttribute(): int
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

    public function getPurchaseOrderReferencesAttribute(): string
    {
        return $this->items
            ->map(fn ($item) => $item->purchaseOrderItem?->purchaseOrder?->reference)
            ->filter()
            ->unique()
            ->implode(', ');
    }
}
