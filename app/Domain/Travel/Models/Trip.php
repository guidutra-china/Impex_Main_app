<?php

namespace App\Domain\Travel\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Travel\Enums\TripStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Trip extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'client_uuid',
        'user_id',
        'company_id',
        'is_internal',
        'title',
        'destination_city',
        'destination_country',
        'start_date',
        'end_date',
        'status',
        'notes',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'is_internal' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Trip $trip) {
            if (empty($trip->created_by)) {
                $trip->created_by = auth()->id();
            }

            if (empty($trip->user_id)) {
                $trip->user_id = auth()->id();
            }
        });

        // Enforce the trip-level link invariant everywhere (admin, API, mobile):
        // a trip belongs to a company OR is an internal expense — never neither.
        static::saving(function (Trip $trip) {
            if ($trip->is_internal) {
                $trip->company_id = null;
            } elseif (empty($trip->company_id)) {
                throw new \InvalidArgumentException(
                    'A trip must belong to a company or be marked as internal.'
                );
            }
        });

        // Editing the header of an already-approved trip reopens it (back to
        // DRAFT) so it must be re-approved — which then refreshes the billing.
        static::updating(function (Trip $trip) {
            $headerFields = [
                'title', 'company_id', 'is_internal', 'destination_city',
                'destination_country', 'start_date', 'end_date', 'notes',
            ];

            // getOriginal applies the cast, so normalize to the enum.
            $original = $trip->getOriginal('status');
            $wasApproved = $original instanceof TripStatus
                ? $original === TripStatus::APPROVED
                : $original === TripStatus::APPROVED->value;

            if ($wasApproved
                && $trip->status === TripStatus::APPROVED
                && $trip->isDirty($headerFields)) {
                // Reopen to the pre-approval state (awaiting approval again).
                $trip->status = TripStatus::SUBMITTED;
                $trip->approved_by = null;
                $trip->approved_at = null;
            }
        });
    }

    /**
     * Reopen an approved trip back to DRAFT (used when its expenses change).
     * Returns true if a revert happened.
     */
    public function revertApprovalIfNeeded(): bool
    {
        if ($this->status !== TripStatus::APPROVED) {
            return false;
        }

        // Reopen to the pre-approval state (awaiting approval again).
        $this->update([
            'status' => TripStatus::SUBMITTED,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'company_id', 'is_internal', 'title', 'approved_by', 'rejected_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('trip')
            ->setDescriptionForEvent(fn (string $eventName) => "Trip {$this->title} was {$eventName}");
    }

    // --- Relationships ---

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // --- Scopes ---

    public function scopeOfStatus($query, TripStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    // --- Accessors ---

    /**
     * Sum of expense amounts (minor units) grouped by currency code.
     *
     * @return array<string, int>
     */
    public function getTotalsByCurrencyAttribute(): array
    {
        return $this->expenses
            ->groupBy('currency_code')
            ->map(fn ($group) => (int) $group->sum('amount'))
            ->all();
    }

    /**
     * Human label for who the trip belongs to: the company name, or the
     * "internal expense" label when there is no linked company.
     */
    public function getCompanyLabelAttribute(): string
    {
        if ($this->is_internal) {
            return __('forms.labels.internal_expense');
        }

        return $this->company?->name ?? '—';
    }
}
