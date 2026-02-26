<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'reference',
        'attachment_path',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'integer',
            'expense_date' => 'date',
            'is_recurring' => 'boolean',
            'recurring_day' => 'integer',
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

    // --- Scopes ---

    public function scopeOfCategory($query, ExpenseCategory $category)
    {
        return $query->where('category', $category);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
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

    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('expense_date', [$start, $end]);
    }
}
