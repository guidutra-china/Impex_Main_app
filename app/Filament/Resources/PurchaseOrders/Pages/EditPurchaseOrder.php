<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
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
