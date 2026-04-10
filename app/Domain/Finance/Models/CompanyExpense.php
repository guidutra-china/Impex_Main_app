<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyExpense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category',
        'description',
        'amount',
        'currency_code',
        'expense_date',
        'is_recurring',
        'recurring_day',
        'payment_method_id',
        'bank_account_id',
        'recurring_source_id',
        'reference',
        'attachment_path',
        'notes',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'status' => ExpenseApprovalStatus::class,
            'amount' => 'integer',
            'expense_date' => 'date',
            'is_recurring' => 'boolean',
            'recurring_day' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyExpense $expense) {
            if (empty($expense->created_by)) {
                $expense->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recurringSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurring_source_id');
    }

    public function recurringPayments(): HasMany
    {
        return $this->hasMany(self::class, 'recurring_source_id');
    }

    // --- Scopes ---

    public function scopeOfCategory($query, ExpenseCategory $category)
    {
        return $query->where('category', $category);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeExcludeRecurringTemplates($query)
    {
        return $query->where('is_recurring', false);
    }

    public function scopeInMonth($query, int $year, int $month)
    {
        return $query->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month);
    }

    public function scopeInYear($query, int $year)
    {
        return $query->whereYear('expense_date', $year);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ExpenseApprovalStatus::APPROVED);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', ExpenseApprovalStatus::PENDING_APPROVAL);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('expense_date', [$start, $end]);
    }
}
