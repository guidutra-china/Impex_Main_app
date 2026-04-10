<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Finance\Models\CompanyExpense;

class ApproveCompanyExpenseAction
{
    public function approve(CompanyExpense $expense): void
    {
        $expense->update([
            'status' => ExpenseApprovalStatus::APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);
    }

    public function reject(CompanyExpense $expense, string $reason): void
    {
        $expense->update([
            'status' => ExpenseApprovalStatus::REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejected_reason' => $reason,
        ]);
    }
}
