<?php

namespace App\Domain\Financial\Models;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTermStage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentScheduleItem extends Model
{
    use HasFactory;

    /**
     * Side tags stitched into `notes` to mark a PSI as belonging to a payable
     * side of an AdditionalCost (amounts Impex pays out), as opposed to the
     * untagged client-receivable row of the same cost.
     */
    public const FORWARDER_PAYABLE_TAG = '[forwarder-payable]';

    public const SUPPLIER_PAYABLE_TAG = '[supplier-payable]';

    protected static function newFactory(): \Database\Factories\PaymentScheduleItemFactory
    {
        return \Database\Factories\PaymentScheduleItemFactory::new();
    }

    protected $fillable = [
        'payable_type',
        'payable_id',
        'shipment_plan_id',
        'shipment_id',
        'payment_term_stage_id',
        'label',
        'percentage',
        'amount',
        'currency_code',
        'due_condition',
        'due_date',
        'status',
        'is_blocking',
        'is_credit',
        'source_type',
        'source_id',
        'sort_order',
        'notes',
        'waived_by',
        'waived_at',
        'overridden_by',
        'overridden_at',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'amount' => 'integer',
            'due_condition' => CalculationBase::class,
            'due_date' => 'date',
            'status' => PaymentScheduleStatus::class,
            'is_blocking' => 'boolean',
            'is_credit' => 'boolean',
            'sort_order' => 'integer',
            'waived_at' => 'datetime',
            'overridden_at' => 'datetime',
        ];
    }

    // --- Scopes ---

    /**
     * Exclui parcelas cujo documento pai (PI, PO, Shipment, Debit Note...) foi
     * cancelado — valores de documentos cancelados não entram em nenhum balance
     * ou relatório financeiro. Todos os payables têm coluna `status`.
     */
    public function scopePayableNotCancelled(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHasMorph('payable', '*', fn ($q) => $q->where('status', '!=', 'cancelled'));
    }

    /**
     * Filters to items tagged with the given side; pass one of the
     * *_PAYABLE_TAG constants.
     */
    public function scopeWithSideTag(\Illuminate\Database\Eloquent\Builder $query, string $tag): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('notes', 'LIKE', "%{$tag}%");
    }

    /**
     * Filters out both payable-side rows; NULL notes counts as untagged.
     */
    public function scopeWithoutSideTags(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('notes')
                ->orWhere(function ($q2) {
                    $q2->where('notes', 'NOT LIKE', '%'.self::FORWARDER_PAYABLE_TAG.'%')
                        ->where('notes', 'NOT LIKE', '%'.self::SUPPLIER_PAYABLE_TAG.'%');
                });
        });
    }

    // --- Relationships ---

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function shipmentPlan(): BelongsTo
    {
        return $this->belongsTo(ShipmentPlan::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function paymentTermStage(): BelongsTo
    {
        return $this->belongsTo(PaymentTermStage::class);
    }

    public function waivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function overriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'credit_schedule_item_id');
    }

    // --- Counterparty resolution ---

    /**
     * The company on the other side of this schedule item, resolved with the
     * payment direction in mind:
     *  - OUTBOUND (we pay): a supplier additional-cost lives under a Shipment
     *    payable, but the party we pay is the cost's supplier; PO → supplier;
     *    supplier DebitNote → its company.
     *  - INBOUND (we receive): PI / client DebitNote / Shipment client cost all
     *    resolve to the document's client company.
     */
    public function counterpartyCompanyId(PaymentDirection $direction): ?int
    {
        if ($direction === PaymentDirection::OUTBOUND
            && $this->source instanceof AdditionalCost
            && $this->source->supplier_company_id) {
            return (int) $this->source->supplier_company_id;
        }

        $payable = $this->payable;

        return match (true) {
            $payable instanceof PurchaseOrder => $payable->supplier_company_id,
            $payable instanceof ProformaInvoice,
            $payable instanceof Shipment,
            $payable instanceof DebitNote,
            $payable instanceof CreditNote => $payable->company_id,
            default => null,
        };
    }

    public function counterpartyName(PaymentDirection $direction): ?string
    {
        if ($direction === PaymentDirection::OUTBOUND
            && $this->source instanceof AdditionalCost
            && $this->source->supplier_company_id) {
            return $this->source->supplierCompany?->name;
        }

        $payable = $this->payable;

        return match (true) {
            $payable instanceof PurchaseOrder => $payable->supplierCompany?->name,
            $payable instanceof ProformaInvoice,
            $payable instanceof Shipment,
            $payable instanceof DebitNote,
            $payable instanceof CreditNote => $payable->company?->name,
            default => null,
        };
    }

    // --- Accessors ---

    /**
     * Batch-computed paid amount injected by OpenItemsSnapshot so dashboard
     * renders don't fire two allocation queries per item. Null = not set,
     * accessor falls through to the live queries.
     *
     * The value lives on the PHP object, NOT in the database — it survives
     * refresh(). Callers that write allocations and then re-read a snapshot
     * item must clear it (setPrecomputedPaidAmount(null)) or fetch a fresh
     * model to see live numbers.
     */
    private ?int $precomputedPaidAmount = null;

    public function setPrecomputedPaidAmount(?int $amount): void
    {
        $this->precomputedPaidAmount = $amount;
    }

    public function getPaidAmountAttribute(): int
    {
        if ($this->precomputedPaidAmount !== null) {
            return $this->precomputedPaidAmount;
        }

        $paymentAmount = (int) $this->allocations()
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');

        $creditAmount = (int) $this->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');

        $ownTotal = $paymentAmount + $creditAmount;

        // For Shipment-owned items, allocations are typically created against
        // the mirror PI/PO items (since users record payments via AR/AP on
        // the document, not on the shipment). We include those mirror
        // allocations here so the Shipment view shows the real paid amount.
        if ($this->payable_type === Shipment::class) {
            $ownTotal += $this->getMirrorPaidAmount();
        }

        return $ownTotal;
    }

    /**
     * Sums paid_amount of mirror items on the corresponding PI/PO for a
     * Shipment-owned schedule item. Mirrors are identified by the document
     * reference extracted from the label ("[SH-XXX / PI-YYY]"), the shipment
     * (payable_id when the item is Shipment-owned, fallback to shipment_id),
     * and when available, payment_term_stage_id.
     */
    protected function getMirrorPaidAmount(): int
    {
        if (! $this->label) {
            return 0;
        }

        if (! preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', $this->label, $m)) {
            return 0;
        }

        // For Shipment-owned items, the shipment is identified by payable_id.
        // Fall back to shipment_id if available.
        $shipmentId = $this->payable_type === Shipment::class
            ? $this->payable_id
            : $this->shipment_id;

        if (! $shipmentId) {
            return 0;
        }

        $docRef = $m[1];
        $isPi = str_starts_with($docRef, 'PI');
        $docClass = $isPi
            ? \App\Domain\ProformaInvoices\Models\ProformaInvoice::class
            : \App\Domain\PurchaseOrders\Models\PurchaseOrder::class;

        $docId = $docClass::where('reference', $docRef)->value('id');
        if (! $docId) {
            return 0;
        }

        $query = static::where('payable_type', $docClass)
            ->where('payable_id', $docId)
            ->where('shipment_id', $shipmentId)
            ->where('id', '!=', $this->id);

        // Narrow by stage only when this item has it set
        if ($this->payment_term_stage_id) {
            $query->where('payment_term_stage_id', $this->payment_term_stage_id);
        } else {
            // Match on stage-identifying prefix of the label (strip "[SH.../PI-...]")
            $cleanLabel = trim(preg_replace('#\s*\x{2014}?\s*\[[^\]]+\]\s*$#u', '', (string) $this->label));
            if ($cleanLabel !== '') {
                $query->where('label', 'LIKE', $cleanLabel.'%');
            }
        }

        $mirrors = $query->pluck('id');

        if ($mirrors->isEmpty()) {
            return 0;
        }

        return (int) \App\Domain\Financial\Models\PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $mirrors)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    public function getCreditAppliedAmountAttribute(): int
    {
        return (int) $this->allocations()
            ->whereNotNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    public function getIsCreditAppliedAttribute(): bool
    {
        if (! $this->is_credit) {
            return false;
        }

        return $this->creditAllocations()->exists();
    }

    /**
     * Pinned rows keep their payable: some payment history (cash allocation or
     * credit application) already references this row against that document.
     */
    public function isPinnedByAllocations(): bool
    {
        return $this->allocations()->exists() || $this->creditAllocations()->exists();
    }

    /**
     * Amount of this credit item consumed by APPROVED payments — via credit
     * applications (allocations pointing at it) or direct cash refunds
     * (allocations paying it). Minor units. Always 0 for non-credit items.
     */
    public function getCreditConsumedAmountAttribute(): int
    {
        if (! $this->is_credit) {
            return 0;
        }

        $applied = (int) $this->creditAllocations()
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');

        $refunded = (int) $this->allocations()
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');

        return $applied + $refunded;
    }

    public function getCreditRemainingAmountAttribute(): int
    {
        if (! $this->is_credit) {
            return 0;
        }

        return max(0, $this->amount - $this->credit_consumed_amount);
    }

    public function getRemainingAmountAttribute(): int
    {
        if ($this->is_credit) {
            return 0;
        }

        return max(0, $this->amount - $this->paid_amount);
    }

    /**
     * Amount paid beyond the scheduled amount (overpayment).
     * Returns 0 if not overpaid. Uses 100 minor unit tolerance for rounding.
     */
    public function getOverpaidAmountAttribute(): int
    {
        if ($this->is_credit) {
            return 0;
        }

        $diff = $this->paid_amount - $this->amount;

        return $diff > 100 ? $diff : 0;
    }

    public function getIsOverpaidAttribute(): bool
    {
        return $this->overpaid_amount > 0;
    }

    public function getEffectiveAmountAttribute(): int
    {
        return $this->is_credit ? -$this->amount : $this->amount;
    }

    public function getIsPaidInFullAttribute(): bool
    {
        if ($this->is_credit) {
            return $this->is_credit_applied || $this->isResolved();
        }

        // Tolerance of 0.01 (100 minor units) to handle percentage rounding differences
        return $this->remaining_amount <= 100;
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    public function isFromAdditionalCost(): bool
    {
        return $this->source_type === AdditionalCost::class;
    }

    /**
     * Whether this item's due date has passed relative to the given day
     * (typically today at start of day). Items without a due date are
     * never considered overdue.
     */
    public function isOverdueAsOf(CarbonImmutable $day): bool
    {
        return $this->due_date !== null
            && $this->due_date->startOfDay()->lt($day);
    }

    /**
     * Reconcile the stored status against the live paid_amount accessor.
     *
     * This function only manages PAID ↔ DUE transitions: the PENDING and
     * OVERDUE statuses are owned by the document lifecycle and the due-date
     * scheduler respectively, not by allocation reconciliation. Removing an
     * allocation does not retroactively "un-activate" a schedule item —
     * it just means the active item is unpaid again.
     *
     * Call this whenever an allocation is created, deleted, or when the
     * parent payment's approval state changes. Refuses to touch WAIVED
     * items (terminal) and credit items (status semantics differ and are
     * managed elsewhere).
     *
     * Without this, mass-delete of allocations via Query Builder bypasses
     * model events and leaves items stuck at PAID with paid_amount = 0.
     */
    public function recalculateStatus(): void
    {
        if ($this->is_credit || $this->status === PaymentScheduleStatus::WAIVED) {
            return;
        }

        $this->refresh();

        if ($this->is_paid_in_full) {
            if ($this->status !== PaymentScheduleStatus::PAID) {
                $this->update(['status' => PaymentScheduleStatus::PAID->value]);
            }

            return;
        }

        // Not paid-in-full but stored as PAID → drift from a prior allocation
        // that is now gone or was silently corrupted. Fall back to DUE so the
        // item shows up in the allocation list and AP report again.
        if ($this->status === PaymentScheduleStatus::PAID) {
            $this->update(['status' => PaymentScheduleStatus::DUE->value]);
        }
    }

    // --- Blocking Logic ---

    public static function blockingConditionsForTransition(Model $payable, string $targetStatus): array
    {
        return static::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('is_blocking', true)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->get()
            ->filter(fn (self $item) => $item->blocksTransitionTo($targetStatus))
            ->values()
            ->all();
    }

    public function blocksTransitionTo(string $targetStatus): bool
    {
        if (! $this->is_blocking || $this->isResolved() || $this->is_credit) {
            return false;
        }

        if ($this->overridden_at !== null) {
            // Override is scoped to PO-related cycles. We only short-circuit
            // transitions that the PO override is meant to unblock.
            $poCycleTransitions = ['confirmed', 'in_production'];
            if (in_array($targetStatus, $poCycleTransitions)) {
                return false;
            }
        }

        return match ($this->due_condition) {
            CalculationBase::BEFORE_PRODUCTION => $targetStatus === 'in_production',
            CalculationBase::BEFORE_SHIPMENT => $targetStatus === 'shipped',
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE => $targetStatus === 'confirmed',
            default => false,
        };
    }

    public function blocksPurchaseOrderGeneration(): bool
    {
        if (! $this->is_blocking || $this->isResolved() || $this->is_credit) {
            return false;
        }

        if ($this->overridden_at !== null) {
            return false;
        }

        return in_array($this->due_condition, [
            CalculationBase::BEFORE_PRODUCTION,
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE,
        ]);
    }

    public static function blockingPurchaseOrderGeneration(Model $payable): array
    {
        return static::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('is_blocking', true)
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->get()
            ->filter(fn (self $item) => $item->blocksPurchaseOrderGeneration())
            ->values()
            ->all();
    }
}
