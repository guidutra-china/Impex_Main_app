<?php

namespace App\Domain\Quotations\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Domain\Infrastructure\Traits\HasReference;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Settings\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Quotation extends Model
{
    use HasDocuments, HasFactory, HasReference, HasStateMachine, SoftDeletes;

    protected $fillable = [
        'reference',
        'inquiry_id',
        'company_id',
        'contact_id',
        'payment_term_id',
        'status',
        'version',
        'currency_code',
        'commission_type',
        'commission_rate',
        'incoterm',
        'show_suppliers',
        'validity_days',
        'valid_until',
        'notes',
        'internal_notes',
        'created_by',
        'responsible_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'commission_type' => CommissionType::class,
            'commission_rate' => 'decimal:2',
            'incoterm' => Incoterm::class,
            'show_suppliers' => 'boolean',
            'validity_days' => 'integer',
            'valid_until' => 'date',
            'version' => 'integer',
        ];
    }

    // --- HasReference ---

    public function getDocumentType(): DocumentType
    {
        return DocumentType::QUOTATION;
    }

    // --- HasStateMachine ---

    public static function allowedTransitions(): array
    {
        return [
            QuotationStatus::DRAFT->value => [
                QuotationStatus::SENT->value,
                QuotationStatus::CANCELLED->value,
            ],
            QuotationStatus::SENT->value => [
                QuotationStatus::NEGOTIATING->value,
                QuotationStatus::APPROVED->value,
                QuotationStatus::REJECTED->value,
                QuotationStatus::EXPIRED->value,
                QuotationStatus::CANCELLED->value,
            ],
            QuotationStatus::NEGOTIATING->value => [
                QuotationStatus::SENT->value,
                QuotationStatus::APPROVED->value,
                QuotationStatus::REJECTED->value,
                QuotationStatus::EXPIRED->value,
                QuotationStatus::CANCELLED->value,
            ],
            QuotationStatus::APPROVED->value => [
                QuotationStatus::CANCELLED->value,
            ],
            QuotationStatus::REJECTED->value => [
                QuotationStatus::DRAFT->value,
            ],
            QuotationStatus::EXPIRED->value => [
                QuotationStatus::DRAFT->value,
            ],
            QuotationStatus::CANCELLED->value => [],
        ];
    }

    // --- Factory ---

    protected static function newFactory(): \Database\Factories\QuotationFactory
    {
        return \Database\Factories\QuotationFactory::new();
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation) {
            if (empty($quotation->valid_until) && $quotation->validity_days) {
                $quotation->valid_until = now()->addDays($quotation->validity_days);
            }

            if (empty($quotation->created_by)) {
                $quotation->created_by = auth()->id();
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

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuotationVersion::class)->orderByDesc('version');
    }

    // --- Accessors ---

    public function getSubtotalAttribute(): int
    {
        return $this->items->sum(fn (QuotationItem $item) => $item->line_total);
    }

    public function getCommissionAmountAttribute(): int
    {
        if ($this->commission_type !== CommissionType::SEPARATE || $this->commission_rate <= 0) {
            return 0;
        }

        return (int) round($this->subtotal * ($this->commission_rate / 100));
    }

    public function getTotalAttribute(): int
    {
        return $this->subtotal + $this->commission_amount;
    }

    public function getTotalConvertedCostAttribute(): int
    {
        return $this->items->sum(fn (QuotationItem $item) => $item->converted_cost_total);
    }

    // --- Scopes ---

    public function scopeForClient($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            QuotationStatus::EXPIRED,
            QuotationStatus::CANCELLED,
        ]);
    }

    /**
     * Freeze the current state into a QuotationVersion snapshot, then bump
     * the live `version` counter. Returns the version number that was just
     * snapshotted (the value frozen, not the new working version).
     */
    public function saveVersion(?string $changeNotes = null, ?int $userId = null): int
    {
        return DB::transaction(function () use ($changeNotes, $userId) {
            $quotation = static::query()->lockForUpdate()->findOrFail($this->id);
            $currentVersion = (int) $quotation->version;

            $quotation->load(['company:id,name', 'contact:id,name,email', 'paymentTerm:id,name']);

            $items = $quotation->items()
                ->with(['suppliers.company:id,name', 'product:id,name,sku', 'selectedSupplier:id,name'])
                ->get()
                ->map(fn (QuotationItem $item) => array_merge($item->toArray(), [
                    'product' => $item->product?->only(['id', 'name', 'sku']),
                    'selected_supplier' => $item->selectedSupplier?->only(['id', 'name']),
                    'suppliers' => $item->suppliers->map(fn ($s) => array_merge($s->toArray(), [
                        'company' => $s->company?->only(['id', 'name']),
                    ]))->toArray(),
                ]))
                ->toArray();

            $snapshot = [
                'quotation' => array_merge($quotation->toArray(), [
                    'company' => $quotation->company?->only(['id', 'name']),
                    'contact' => $quotation->contact?->only(['id', 'name', 'email']),
                    'payment_term' => $quotation->paymentTerm?->only(['id', 'name']),
                    'subtotal' => $quotation->subtotal,
                    'commission_amount' => $quotation->commission_amount,
                    'total' => $quotation->total,
                    'total_converted_cost' => $quotation->total_converted_cost,
                ]),
                'items' => $items,
            ];

            QuotationVersion::create([
                'quotation_id' => $quotation->id,
                'version' => $currentVersion,
                'snapshot' => $snapshot,
                'change_notes' => $changeNotes,
                'created_by' => $userId,
            ]);

            $quotation->increment('version');

            return $currentVersion;
        });
    }
}
