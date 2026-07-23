<?php

namespace App\Domain\Financial\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Actions\GenerateReferenceAction;
use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Documento de crédito a favor de um cliente (desconto, devolução,
 * acerto comercial) ou de um fornecedor (dedução de qualidade, claim,
 * falta de mercadoria). É a contrapartida da Nota de Débito.
 *
 * A emissão cria PaymentScheduleItems com is_credit=true (payable = a
 * própria CN). O crédito é consumido por aplicação contra parcelas em
 * aberto (PaymentAllocation.credit_schedule_item_id) ou por reembolso em
 * dinheiro (Payment alocado diretamente na parcela de crédito).
 */
class CreditNote extends Model
{
    use SoftDeletes;

    /** Tolerância de liquidação em minor units (escala Money::SCALE = 0.01). */
    public const SETTLEMENT_TOLERANCE = 100;

    protected $fillable = [
        'reference',
        'company_id',
        'party_type',
        'proforma_invoice_id',
        'purchase_order_id',
        'shipment_id',
        'total_amount',
        'currency_code',
        'status',
        'issued_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatus::class,
            'party_type' => PartyType::class,
            'total_amount' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CreditNote $creditNote) {
            if (empty($creditNote->reference)) {
                $creditNote->reference = static::generateReference();
            }
            if (empty($creditNote->created_by)) {
                $creditNote->created_by = auth()->id();
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
        return app(GenerateReferenceAction::class)->execute(DocumentType::CREDIT_NOTE);
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

    public function lineItems(): HasMany
    {
        return $this->hasMany(CreditNoteLineItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // --- Accessors ---

    /**
     * IDs dos PaymentScheduleItems de crédito originados das line items.
     */
    public function creditScheduleItemIds(): Collection
    {
        $lineItemIds = $this->lineItems()->pluck('id');

        if ($lineItemIds->isEmpty()) {
            return collect();
        }

        return PaymentScheduleItem::query()
            ->where('source_type', CreditNoteLineItem::class)
            ->whereIn('source_id', $lineItemIds)
            ->pluck('id');
    }

    /**
     * Total consumido por APLICAÇÃO contra parcelas em aberto (alocações de
     * crédito de pagamentos APROVADOS). Minor units.
     */
    public function getAppliedAmountAttribute(): int
    {
        $psiIds = $this->creditScheduleItemIds();

        if ($psiIds->isEmpty()) {
            return 0;
        }

        return (int) PaymentAllocation::query()
            ->whereIn('credit_schedule_item_id', $psiIds)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    /**
     * Total devolvido em DINHEIRO (Payment alocado diretamente na parcela
     * de crédito, sem credit_schedule_item_id). Minor units.
     */
    public function getRefundedAmountAttribute(): int
    {
        $psiIds = $this->creditScheduleItemIds();

        if ($psiIds->isEmpty()) {
            return 0;
        }

        return (int) PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $psiIds)
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    public function getConsumedAmountAttribute(): int
    {
        return $this->applied_amount + $this->refunded_amount;
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(0, ((int) $this->total_amount) - $this->consumed_amount);
    }

    /**
     * Pagamentos APROVADOS que consumiram esta CN — por aplicação de
     * crédito ou por reembolso em dinheiro.
     */
    public function getPaymentsAttribute(): Collection
    {
        $psiIds = $this->creditScheduleItemIds();

        if ($psiIds->isEmpty()) {
            return collect();
        }

        return Payment::query()
            ->whereHas('allocations', function ($q) use ($psiIds) {
                $q->whereIn('credit_schedule_item_id', $psiIds)
                    ->orWhere(function ($q2) use ($psiIds) {
                        $q2->whereIn('payment_schedule_item_id', $psiIds)
                            ->whereNull('credit_schedule_item_id');
                    });
            })
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

    /**
     * Deriva o status do consumo real (aplicações + reembolsos aprovados).
     * Não toca DRAFT nem CANCELLED — esses pertencem ao ciclo do documento.
     */
    public function recalculateStatus(): void
    {
        if (in_array($this->status, [CreditNoteStatus::DRAFT, CreditNoteStatus::CANCELLED], true)) {
            return;
        }

        $applied = $this->applied_amount;
        $refunded = $this->refunded_amount;
        $consumed = $applied + $refunded;
        $remaining = ((int) $this->total_amount) - $consumed;

        $newStatus = match (true) {
            $consumed <= 0 => CreditNoteStatus::ISSUED,
            $remaining > self::SETTLEMENT_TOLERANCE => CreditNoteStatus::PARTIALLY_APPLIED,
            $applied <= 0 && $refunded > 0 => CreditNoteStatus::REFUNDED,
            default => CreditNoteStatus::APPLIED,
        };

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }
}
