<?php

namespace App\Domain\ProformaInvoices\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ConfirmationMethod;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProformaInvoice extends Model
{
    use HasFactory, SoftDeletes, HasReference, HasStateMachine, HasDocuments;

    protected $fillable = [
        'reference',
        'inquiry_id',
        'company_id',
        'contact_id',
        'payment_term_id',
        'status',
        'currency_code',
        'incoterm',
        'issue_date',
        'valid_until',
        'validity_days',
        'confirmation_method',
        'confirmation_reference',
        'confirmed_at',
        'confirmed_by',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProformaInvoiceStatus::class,
            'confirmation_method' => ConfirmationMethod::class,
            'issue_date' => 'date',
            'valid_until' => 'date',
            'validity_days' => 'integer',
            'confirmed_at' => 'datetime',
            'incoterm' => \App\Domain\Quotations\Enums\Incoterm::class,
        ];
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::PROFORMA_INVOICE;
    }

    // --- HasStateMachine ---

    public static function allowedTransitions(): array
    {
        return [
            ProformaInvoiceStatus::DRAFT->value => [
                ProformaInvoiceStatus::SENT->value,
                ProformaInvoiceStatus::CANCELLED->value,
            ],
            ProformaInvoiceStatus::SENT->value => [
                ProformaInvoiceStatus::CONFIRMED->value,
                ProformaInvoiceStatus::CANCELLED->value,
            ],
            ProformaInvoiceStatus::CONFIRMED->value => [
                ProformaInvoiceStatus::CANCELLED->value,
            ],
            ProformaInvoiceStatus::CANCELLED->value => [
                ProformaInvoiceStatus::DRAFT->value,
            ],
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (ProformaInvoice $pi) {
            if (empty($pi->issue_date)) {
                $pi->issue_date = now()->toDateString();
            }

            if (empty($pi->valid_until) && $pi->validity_days) {
                $pi->valid_until = now()->addDays($pi->validity_days);
            }

            if (empty($pi->created_by)) {
                $pi->created_by = auth()->id();
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

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class)->orderBy('sort_order');
    }

    public function quotations(): BelongsToMany
    {
        return $this->belongsToMany(Quotation::class, 'proforma_invoice_quotation')
            ->withTimestamps();
    }

    // --- Accessors ---

    public function getSubtotalAttribute(): int
    {
        return $this->items->sum(fn (ProformaInvoiceItem $item) => $item->line_total);
    }

    public function getTotalAttribute(): int
    {
        return $this->subtotal;
    }

    public function getCostTotalAttribute(): int
    {
        return $this->items->sum(fn (ProformaInvoiceItem $item) => $item->cost_total);
    }

    public function getMarginAttribute(): float
    {
        if ($this->cost_total <= 0) {
            return 0;
        }

        return round((($this->total - $this->cost_total) / $this->cost_total) * 100, 2);
    }

    // --- Scopes ---

    public function scopeForClient($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            ProformaInvoiceStatus::CANCELLED,
        ]);
    }
}
