<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Logistics\Actions\RecalculatePaymentScheduleForShipmentAction;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Actions\ReconcileShipmentPlanAction;
use App\Filament\RelationManagers\PaymentScheduleRelationManager as BasePaymentScheduleRelationManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Table;

class PaymentScheduleRelationManager extends BasePaymentScheduleRelationManager
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                $this->recalculateForShipmentAction(),
                $this->generateScheduleAction(),
                $this->regenerateScheduleAction(),
            ]);
    }

    protected function recalculateForShipmentAction(): Action
    {
        return Action::make('recalculateForShipment')
            ->label(__('forms.labels.recalculate_shipment_schedule'))
            ->icon('heroicon-o-calculator')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('generate-payment-schedule'))
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.recalculate_shipment_schedule'))
            ->modalDescription('This will recalculate payment schedule items based on the shipment items, linked proforma invoices, and shipment dates (ETD/ETA). Paid and waived items will be preserved.')
            ->action(function () {
                $shipment = $this->getOwnerRecord();

                if (! $shipment instanceof Shipment) {
                    return;
                }

                $plan = $shipment->shipmentPlan;

                if ($plan && $plan->shipment_id) {
                    $plan->load('items.proformaInvoiceItem');
                    $shipment->load('items.proformaInvoiceItem');

                    app(ReconcileShipmentPlanAction::class)->execute($plan);
                } else {
                    app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment);
                }

                Notification::make()
                    ->title(__('messages.payment_schedule_recalculated'))
                    ->success()
                    ->send();
            });
    }
}
