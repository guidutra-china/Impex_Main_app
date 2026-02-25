<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
            $this->cancelAction(),
            EditAction::make()
                ->visible(fn () => in_array($this->record->status, [
                    PaymentStatus::PENDING_APPROVAL,
                    PaymentStatus::REJECTED,
                ])),
        ];
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Payment')
            ->modalDescription(fn () => 'Approve payment of '
                . Money::format($this->record->amount) . ' '
                . $this->record->currency_code . ' to/from '
                . ($this->record->company?->name ?? 'Unknown') . '?')
            ->visible(fn () => $this->record->status === PaymentStatus::PENDING_APPROVAL
                && auth()->user()?->can('approve-payments'))
            ->action(function () {
                app(ApprovePaymentAction::class)->approve($this->record);

                Notification::make()->title('Payment approved')->success()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at']);
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject Payment')
            ->form([
                Textarea::make('reason')
                    ->label('Rejection Reason')
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn () => $this->record->status === PaymentStatus::PENDING_APPROVAL
                && auth()->user()?->can('reject-payments'))
            ->action(function (array $data) {
                app(ApprovePaymentAction::class)->reject($this->record, $data['reason']);

                Notification::make()->title('Payment rejected')->danger()->send();

                $this->refreshFormData(['status', 'notes']);
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel Payment')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel Payment')
            ->modalDescription(fn () => 'Cancel payment of '
                . Money::format($this->record->amount) . ' '
                . $this->record->currency_code . '? '
                . ($this->record->status === PaymentStatus::APPROVED
                    ? 'This will reverse the effect on schedule item statuses.'
                    : ''))
            ->form([
                Textarea::make('reason')
                    ->label('Cancellation Reason')
                    ->rows(2),
            ])
            ->visible(fn () => in_array($this->record->status, [
                PaymentStatus::PENDING_APPROVAL,
                PaymentStatus::APPROVED,
                PaymentStatus::REJECTED,
            ]) && auth()->user()?->can('delete-payments'))
            ->action(function (array $data) {
                app(ApprovePaymentAction::class)->cancel($this->record, $data['reason'] ?? null);

                Notification::make()->title('Payment cancelled')->warning()->send();

                $this->refreshFormData(['status', 'notes']);
            });
    }
}
