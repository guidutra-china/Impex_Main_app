<?php

namespace App\Domain\Financial\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Actions\GenerateReferenceAction;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DebitNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'company_id',
        'party_type',
        'proforma_invoice_id',
        'purchase_order_id',
        'shipment_id',
        'trip_id',
        'total_amount',
        'currency_code',
        'status',
        'issued_at',
        'due_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DebitNoteStatus::class,
            'party_type' => PartyType::class,
            'total_amount' => 'integer',
            'issued_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DebitNote $debitNote) {
            if (empty($debitNote->reference)) {
                $debitNote->reference = static::generateReference();
            }
            if (empty($debitNote->created_by)) {
                $debitNote->created_by = auth()->id();
            }
        });
    }

    /**
     * Contador em reference_sequences com lockForUpdate — o antigo scan de
     * max(sufixo)+1 entregava o mesmo número em concorrência e reemitia
     * números após force-delete. Sequências existentes são semeadas pela
     * migração 2026_07_23_120000.
     */
    public static function generateReference(): string
    {
        return app(GenerateReferenceAction::class)->execute(DocumentType::DEBIT_NOTE);
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Travel\Models\Trip::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(DebitNoteLineItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // --- Accessors ---

    /**
     * Total já liquidado em alocações de pagamento APROVADAS contra os
     * PaymentScheduleItems originados das line items desta Debit Note.
     * Valor em minor units (escala Money::SCALE).
     */
    public function getPaidAmountAttribute(): int
    {
        $lineItemIds = $this->lineItems()->pluck('id');

        if ($lineItemIds->isEmpty()) {
            return 0;
        }

        $psiIds = PaymentScheduleItem::query()
            ->where('source_type', DebitNoteLineItem::class)
            ->whereIn('source_id', $lineItemIds)
            ->pluck('id');

        if ($psiIds->isEmpty()) {
            return 0;
        }

        return (int) PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $psiIds)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(0, ((int) $this->total_amount) - $this->paid_amount);
    }

    /**
     * Pagamentos (com data e referência) que tocaram esta Debit Note via
     * alocações nos PaymentScheduleItems derivados das line items.
     */
    public function getPaymentsAttribute(): Collection
    {
        $lineItemIds = $this->lineItems()->pluck('id');

        if ($lineItemIds->isEmpty()) {
            return Payment::query()->whereRaw('0 = 1')->get();
        }

        $psiIds = PaymentScheduleItem::query()
            ->where('source_type', DebitNoteLineItem::class)
            ->whereIn('source_id', $lineItemIds)
            ->pluck('id');

        if ($psiIds->isEmpty()) {
            return Payment::query()->whereRaw('0 = 1')->get();
        }

        return Payment::query()
            ->whereHas('allocations', fn ($q) => $q->whereIn('payment_schedule_item_id', $psiIds))
            ->where('status', PaymentStatus::APPROVED)
            ->orderByDesc('payment_date')
            ->get();
    }

    // --- Helpers ---

    public function recalculateTotal(): void
    {
        $this->update([
            'total_amount' => $this->lineItems()->sum('amount'),
        ]);
    }
}
