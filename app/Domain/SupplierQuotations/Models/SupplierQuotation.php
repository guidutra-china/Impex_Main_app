<?php

namespace App\Domain\SupplierQuotations\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierQuotation extends Model
{
    use HasFactory, SoftDeletes, HasReference, HasStateMachine, HasDocuments;

    protected $fillable = [
        'reference',
        'inquiry_id',
        'company_id',
        'contact_id',
        'status',
        'currency_code',
        'supplier_reference',
        'requested_at',
        'received_at',
        'valid_until',
        'lead_time_days',
        'moq',
        'incoterm',
        'payment_term_id',
        'notes',
        'internal_notes',
        'rfq_instructions',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierQuotationStatus::class,
            'requested_at' => 'date',
            'received_at' => 'date',
            'valid_until' => 'date',
        ];
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::SUPPLIER_QUOTATION;
    }

    // --- HasStateMachine ---

    public static function allowedTransitions(): array
    {
        return [
            SupplierQuotationStatus::REQUESTED->value => [
                SupplierQuotationStatus::RECEIVED->value,
                SupplierQuotationStatus::EXPIRED->value,
            ],
            SupplierQuotationStatus::RECEIVED->value => [
                SupplierQuotationStatus::UNDER_ANALYSIS->value,
                SupplierQuotationStatus::REJECTED->value,
                SupplierQuotationStatus::EXPIRED->value,
            ],
            SupplierQuotationStatus::UNDER_ANALYSIS->value => [
                SupplierQuotationStatus::SELECTED->value,
                SupplierQuotationStatus::REJECTED->value,
            ],
            SupplierQuotationStatus::SELECTED->value => [
                SupplierQuotationStatus::REJECTED->value,
            ],
            SupplierQuotationStatus::REJECTED->value => [
                SupplierQuotationStatus::UNDER_ANALYSIS->value,
            ],
            SupplierQuotationStatus::EXPIRED->value => [],
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (SupplierQuotation $sq) {
            if (empty($sq->requested_at)) {
                $sq->requested_at = now()->toDateString();
            }
            if (empty($sq->created_by)) {
                $sq->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function items(): HasMany
    {
        return $this->hasMany(SupplierQuotationItem::class)->orderBy('sort_order');
    }

    // --- Helpers ---

    public function getTotalCostAttribute(): int
    {
        return $this->items->sum('total_cost');
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    // --- Scopes ---

    public function scopeForSupplier($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForInquiry($query, int $inquiryId)
    {
        return $query->where('inquiry_id', $inquiryId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            SupplierQuotationStatus::REJECTED,
            SupplierQuotationStatus::EXPIRED,
        ]);
    }
}
