<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Domain\Finance\Actions\ApproveCompanyExpenseAction;
use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCompanyExpense extends ViewRecord
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
            EditAction::make()
                ->visible(fn () => in_array($this->record->status, [
                    ExpenseApprovalStatus::DRAFT,
                    ExpenseApprovalStatus::PENDING_APPROVAL,
                    ExpenseApprovalStatus::REJECTED,
                ])),
            Action::make('backToList')
                ->label(__('forms.labels.back_to_list'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(CompanyExpenseResource::getUrl('index')),
        ];
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('forms.labels.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.approve'))
            ->modalDescription(fn () => 'Approve expense of '
                .Money::format($this->record->amount).' '
                .$this->record->currency_code.'?')
            ->visible(fn () => $this->record->status === ExpenseApprovalStatus::PENDING_APPROVAL
                && auth()->user()?->can('approve-company-expenses'))
            ->action(function () {
                app(ApproveCompanyExpenseAction::class)->approve($this->record);

                Notification::make()->title(__('messages.expense_approved'))->success()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at']);
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('forms.labels.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.reject'))
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.rejection_reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn () => $this->record->status === ExpenseApprovalStatus::PENDING_APPROVAL
                && auth()->user()?->can('approve-company-expenses'))
            ->action(function (array $data) {
                app(ApproveCompanyExpenseAction::class)->reject($this->record, $data['reason']);

                Notification::make()->title(__('messages.expense_rejected'))->danger()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at', 'rejected_reason']);
            });
    }
}
