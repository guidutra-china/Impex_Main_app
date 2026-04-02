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
        'cost_date',
        'status',
        'notes',
        'attachment_path',
        'created_by',
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
            'cost_date' => 'date',
            'status' => AdditionalCostStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdditionalCost $cost) {
            if (empty($cost->created_by)) {
                $cost->created_by = auth()->id();
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
}
