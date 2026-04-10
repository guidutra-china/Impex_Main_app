<?php

namespace App\Domain\Financial\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;
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
        'proforma_invoice_id',
        'shipment_id',
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

    public static function generateReference(): string
    {
        $year = now()->year;
        $lastNumber = static::withTrashed()
            ->where('reference', 'like', "DN-{$year}-%")
            ->count();

        return sprintf('DN-%d-%04d', $year, $lastNumber + 1);
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

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(DebitNoteLineItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // --- Helpers ---

    public function recalculateTotal(): void
    {
        $this->update([
            'total_amount' => $this->lineItems()->sum('amount'),
        ]);
    }
}
