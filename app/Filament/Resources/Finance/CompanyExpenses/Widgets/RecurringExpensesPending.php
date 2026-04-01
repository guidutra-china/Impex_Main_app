<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Widgets;

use App\Domain\Finance\Models\CompanyExpense;
use App\Domain\Infrastructure\Support\Money;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class RecurringExpensesPending extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recurring-expenses-pending';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-company-expenses') ?? false;
    }

    public function registerPayment(int $templateId): void
    {
        $template = CompanyExpense::query()
            ->where('is_recurring', true)
            ->findOrFail($templateId);

        CompanyExpense::create([
            'category' => $template->getRawOriginal('category'),
            'description' => $template->description,
            'amount' => $template->amount,
            'currency_code' => $template->currency_code,
            'expense_date' => Carbon::now(),
            'is_recurring' => false,
            'payment_method_id' => $template->payment_method_id,
            'bank_account_id' => $template->bank_account_id,
            'recurring_source_id' => $template->id,
            'notes' => $template->notes,
            'created_by' => auth()->id(),
        ]);

        Notification::make()
            ->title(__('widgets.recurring.payment_registered'))
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $now = Carbon::now();

        $templates = CompanyExpense::query()
            ->where('is_recurring', true)
            ->orderBy('recurring_day')
            ->orderBy('description')
            ->get();

        $paidThisMonth = CompanyExpense::query()
            ->whereNotNull('recurring_source_id')
            ->whereYear('expense_date', $now->year)
            ->whereMonth('expense_date', $now->month)
            ->pluck('recurring_source_id')
            ->toArray();

        $items = $templates->map(fn (CompanyExpense $template) => [
            'id' => $template->id,
            'description' => $template->description,
            'category' => $template->category,
            'amount' => Money::format($template->amount),
            'currency_code' => $template->currency_code,
            'recurring_day' => $template->recurring_day,
            'payment_method' => $template->paymentMethod?->name,
            'is_paid' => in_array($template->id, $paidThisMonth),
        ]);

        $pendingCount = $items->where('is_paid', false)->count();
        $paidCount = $items->where('is_paid', true)->count();

        return [
            'items' => $items,
            'pendingCount' => $pendingCount,
            'paidCount' => $paidCount,
            'currentMonthLabel' => $now->translatedFormat('F Y'),
        ];
    }
}
