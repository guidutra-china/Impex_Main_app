<?php

namespace App\Domain\PurchaseOrders\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Financial\Traits\HasPaymentSchedule;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\ProformaInvoices\Enums\ConfirmationMethod;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes, HasReference, HasStateMachine, HasDocuments, HasPaymentSchedule, LogsActivity;

    protected $fillable = [
        'reference',
        'proforma_invoice_id',
        'supplier_company_id',
        'contact_id',
        'payment_term_id',
        'status',
        'currency_code',
        'incoterm',
        'issue_date',
        'expected_delivery_date',
        'confirmation_method',
        'confirmation_reference',
        'confirmed_at',
        'confirmed_by',
        'notes',
        'internal_notes',
        'shipping_instructions',
        'supplier_invoice_number',
        'supplier_invoice_date',
        'created_by',
        'responsible_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'confirmation_method' => ConfirmationMethod::class,
            'incoterm' => Incoterm::class,
            'issue_date' => 'date',
            'expected_delivery_date' => 'date',
            'confirmed_at' => 'datetime',
            'supplier_invoice_date' => 'date',
        ];
    }

    // --- Activity Log ---

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('purchase_order')
            ->setDescriptionForEvent(fn (string $eventName) => "Purchase Order {$this->reference} was {$eventName}");
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::PURCHASE_ORDER;
    }

    // --- HasStateMachine ---

    public static function allowedTransitions(): array
    {
        return [
            PurchaseOrderStatus::DRAFT->value => [
                PurchaseOrderStatus::SENT->value,
                PurchaseOrderStatus::CANCELLED->value,
            ],
            PurchaseOrderStatus::SENT->value => [
                PurchaseOrderStatus::CONFIRMED->value,
                PurchaseOrderStatus::CANCELLED->value,
            ],
            PurchaseOrderStatus::CONFIRMED->value => [
                PurchaseOrderStatus::IN_PRODUCTION->value,
                PurchaseOrderStatus::SHIPPED->value,
                PurchaseOrderStatus::CANCELLED->value,
            ],
            PurchaseOrderStatus::IN_PRODUCTION->value => [
                PurchaseOrderStatus::SHIPPED->value,
                PurchaseOrderStatus::CANCELLED->value,
            ],
            PurchaseOrderStatus::SHIPPED->value => [
                PurchaseOrderStatus::COMPLETED->value,
            ],
            PurchaseOrderStatus::COMPLETED->value => [],
            PurchaseOrderStatus::CANCELLED->value => [
                PurchaseOrderStatus::DRAFT->value,
            ],
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            if (empty($po->issue_date)) {
                $po->issue_date = now()->toDateString();
            }

            if (empty($po->created_by)) {
                $po->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    // --- Accessors ---

    public function getTotalAttribute(): int
    {
        return $this->items->sum(fn (PurchaseOrderItem $item) => $item->line_total);
    }

    // --- Scopes ---

    public function scopeForSupplier($query, int $companyId)
    {
        return $query->where('supplier_company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            PurchaseOrderStatus::CANCELLED,
        ]);
    }
}
