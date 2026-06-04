<?php

namespace App\Domain\Travel\Models;

use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TripExpense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'trip_id',
        'client_uuid',
        'category',
        'description',
        'amount',
        'currency_code',
        'expense_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => TravelExpenseCategory::class,
            'amount' => 'integer',
            'expense_date' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TripExpense $expense) {
            if (empty($expense->created_by)) {
                $expense->created_by = auth()->id();
            }
        });

        // Changing a trip's expenses after it was approved reopens the trip so
        // the billing is regenerated on the next approval.
        static::saved(fn (TripExpense $expense) => $expense->trip?->revertApprovalIfNeeded());
        static::deleted(fn (TripExpense $expense) => $expense->trip?->revertApprovalIfNeeded());
    }

    // --- Relationships ---

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TripExpensePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
