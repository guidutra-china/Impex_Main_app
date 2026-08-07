<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

trait HasPaymentApprovalActions
{
    public static function approvalRecordActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('approve')
                    ->label(__('forms.labels.approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Payment')
                    ->modalDescription(fn ($record) => 'Approve payment of '
                        .Money::format($record->amount).' '
                        .$record->currency_code.'?')
                    ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
                    ->action(function ($record) {
                        app(ApprovePaymentAction::class)->approve($record);
                        Notification::make()->title(__('messages.payment_approved'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('forms.labels.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Payment')
                    ->form([
                        Textarea::make('reason')
                            ->label(__('forms.labels.rejection_reason'))
                            ->rows(2)
                            ->required(),
                    ])
                    ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
                    ->action(function ($record, array $data) {
                        app(ApprovePaymentAction::class)->reject($record, $data['reason']);
                        Notification::make()->title(__('messages.payment_rejected'))->danger()->send();
                    }),
                Action::make('cancel_payment')
                    ->label(__('forms.labels.cancel_payment'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel Payment')
                    ->form([
                        Textarea::make('reason')
                            ->label(__('forms.labels.cancellation_reason'))
                            ->rows(2),
                    ])
                    ->visible(fn ($record) => in_array($record->status, [
                        PaymentStatus::PENDING_APPROVAL,
                        PaymentStatus::APPROVED,
                        PaymentStatus::REJECTED,
                    ]))
                    ->action(function ($record, array $data) {
                        app(ApprovePaymentAction::class)->cancel($record, $data['reason'] ?? null);
                        Notification::make()->title('Payment cancelled')->warning()->send();
                    }),
            ])
                ->label(__('forms.labels.change_status'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->size('sm')
                ->visible(fn ($record) => $record->status !== PaymentStatus::CANCELLED),
        ];
    }

    public static function viewAndEditActions(): array
    {
        return [
            ViewAction::make(),
            EditAction::make(),
        ];
    }

    /**
     * Row shortcut that deep-links to the View page with the
     * "manageAllocations" modal already open (?action=manageAllocations).
     */
    public static function allocationsRecordAction(string $resource): Action
    {
        return Action::make('allocations')
            ->label(__('forms.labels.allocations'))
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->size('sm')
            ->visible(fn ($record) => $record->status === PaymentStatus::APPROVED)
            ->url(fn ($record) => $resource::getUrl('view', [
                'record' => $record,
                'action' => 'manageAllocations',
            ]));
    }
}
