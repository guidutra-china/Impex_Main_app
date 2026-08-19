<?php

namespace App\Domain\Financial\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Quotations\Enums\CommissionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class AdditionalCost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'costable_type',
        'costable_id',
        'cost_type',
        'commission_rate',
        'commission_mode',
        'description',
        'amount',
        'currency_code',
        'exchange_rate',
        'amount_in_document_currency',
        'billable_to',
        'supplier_company_id',
        'forwarder_company_id',
        'forwarder_amount',
        'forwarder_currency_code',
        'forwarder_exchange_rate',
        'forwarder_amount_in_document_currency',
        'supplier_payable_amount',
        'supplier_payable_currency_code',
        'supplier_payable_exchange_rate',
        'supplier_payable_amount_in_document_currency',
        'supplier_payable_due_date',
        'supplier_payable_status',
        'cost_date',
        'status',
        'forwarder_status',
        'notes',
        'attachment_path',
        'created_by',
        'source_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'cost_type' => AdditionalCostType::class,
            'commission_rate' => 'decimal:2',
            'commission_mode' => CommissionType::class,
            'amount' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount_in_document_currency' => 'integer',
            'billable_to' => BillableTo::class,
            'forwarder_amount' => 'integer',
            'forwarder_exchange_rate' => 'decimal:8',
            'forwarder_amount_in_document_currency' => 'integer',
            'supplier_payable_amount' => 'integer',
            'supplier_payable_exchange_rate' => 'decimal:8',
            'supplier_payable_amount_in_document_currency' => 'integer',
            'supplier_payable_due_date' => 'date',
            'supplier_payable_status' => AdditionalCostStatus::class,
            'cost_date' => 'date',
            'status' => AdditionalCostStatus::class,
            'forwarder_status' => AdditionalCostStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdditionalCost $cost) {
            if (empty($cost->created_by)) {
                $cost->created_by = auth()->id();
            }
        });

        static::updating(function (AdditionalCost $cost) {
            if ($cost->isDirty('cost_type')) {
                $cost->assertCostTypeSwitchable($cost->cost_type);
            }
        });

        static::saving(function (AdditionalCost $cost) {
            // Desconto absorvido pela empresa = simplesmente não lançar o
            // custo; COMPANY não faz sentido como parte do desconto.
            if ($cost->cost_type === AdditionalCostType::DISCOUNT && $cost->billable_to === BillableTo::COMPANY) {
                throw new \InvalidArgumentException('A DISCOUNT cost cannot be company-billable.');
            }

            // Invariante do tipo DISCOUNT: colunas do custo sempre negativas
            // (documentos somam naturalmente); PSIs derivados usam abs().
            if ($cost->cost_type === AdditionalCostType::DISCOUNT) {
                if ($cost->amount !== null) {
                    $cost->amount = -abs((int) $cost->amount);
                }
                if ($cost->amount_in_document_currency !== null) {
                    $cost->amount_in_document_currency = -abs((int) $cost->amount_in_document_currency);
                }
            }
        });
    }

    // --- Relationships ---

    public function costable(): MorphTo
    {
        return $this->morphTo();
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function forwarderCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'forwarder_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Payment this cost was entered from (bank fees). Non-null means the
     * payment owns the cost and is the only place it should be edited.
     */
    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function isFromPayment(): bool
    {
        return $this->source_payment_id !== null;
    }

    public function debitNoteLineItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DebitNoteLineItem::class);
    }

    public function creditNoteLineItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CreditNoteLineItem::class);
    }

    // --- Scopes ---

    public function scopeBillableToClient($query)
    {
        return $query->where('billable_to', BillableTo::CLIENT);
    }

    public function scopeBillableToSupplier($query)
    {
        return $query->where('billable_to', BillableTo::SUPPLIER);
    }

    public function scopeBillableToCompany($query)
    {
        return $query->where('billable_to', BillableTo::COMPANY);
    }

    public function scopeOfType($query, AdditionalCostType $type)
    {
        return $query->where('cost_type', $type);
    }

    // --- Supplier payable side ---

    /**
     * True when this cost carries a "payable to supplier" side: an amount
     * Impex owes a supplier for this cost (e.g. logo development billed by
     * the factory). Mutually exclusive with billable_to=SUPPLIER, which
     * reuses supplier_company_id with the opposite meaning (a credit
     * charged TO the supplier).
     */
    public function hasSupplierPayableSide(): bool
    {
        return $this->billable_to !== BillableTo::SUPPLIER
            && $this->supplier_company_id !== null
            && (int) $this->supplier_payable_amount > 0;
    }

    public function isDiscount(): bool
    {
        return $this->cost_type === AdditionalCostType::DISCOUNT;
    }

    /**
     * Trocar o tipo entre desconto (crédito) e custo comum inverte a natureza
     * do PSI; com alocações registradas isso corromperia o histórico. Compara
     * o tipo persistido (original) com o proposto, então funciona tanto na
     * validação prévia do form quanto no hook updating (model já dirty).
     */
    public function assertCostTypeSwitchable(AdditionalCostType|string|null $newType): void
    {
        if ($newType === null) {
            return;
        }

        $originalType = $this->exists ? $this->getOriginal('cost_type') : $this->cost_type;
        $newValue = $newType instanceof AdditionalCostType ? $newType->value : $newType;
        $wasDiscount = $originalType === AdditionalCostType::DISCOUNT;
        $willBeDiscount = $newValue === AdditionalCostType::DISCOUNT->value;

        if ($wasDiscount === $willBeDiscount) {
            return;
        }

        if ($this->hasSettlementHistory()) {
            throw ValidationException::withMessages([
                'cost_type' => __('forms.validation.cost_type_locked_by_allocations'),
            ]);
        }
    }

    /**
     * True when any schedule item derived from this cost carries payment
     * history — cash allocations OR credit applications (a consumed credit
     * leg has rows only in creditAllocations). Guards delete/cleanup paths.
     */
    public function hasSettlementHistory(): bool
    {
        return PaymentScheduleItem::where('source_type', self::class)
            ->where('source_id', $this->id)
            ->where(function ($q) {
                $q->whereHas('allocations')->orWhereHas('creditAllocations');
            })
            ->exists();
    }
}
