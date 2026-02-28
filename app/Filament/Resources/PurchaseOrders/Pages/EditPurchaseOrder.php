<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->transitionStatusAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function transitionStatusAction(): Action
    {
        return Action::make('transitionStatus')
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
            ->form(function () {
                $allowed = $this->record->getAllowedNextStatuses();
                $options = collect($allowed)->mapWithKeys(function ($status) {
                    $enum = PurchaseOrderStatus::from($status);
                    return [$status => $enum->getLabel()];
                })->toArray();

                return [
                    Select::make('new_status')
                        ->label(__('forms.labels.new_status'))
                        ->options($options)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('forms.labels.transition_notes'))
                        ->rows(2)
                        ->maxLength(1000),
                ];
            })
            ->action(function (array $data) {
                try {
                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        PurchaseOrderStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . PurchaseOrderStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function beforeSave(): void
    {
        $record = $this->getRecord();
        $newStatus = $this->data['status'] ?? null;
        $currentStatus = $record->getCurrentStatus();

        if ($newStatus && $newStatus !== $currentStatus) {
            $blockers = PaymentScheduleItem::blockingConditionsForTransition($record, $newStatus);

            if (count($blockers) > 0) {
                $labels = collect($blockers)->pluck('label')->implode(', ');

                Notification::make()
                    ->title(__('messages.status_change_blocked'))
                    ->body("The following payments must be resolved before transitioning to this status: {$labels}")
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }
        }
    }
}
