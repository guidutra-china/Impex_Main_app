<?php

namespace App\Filament\Resources\ShipmentPlans\Concerns;

use App\Domain\Planning\Actions\ConfirmShipmentPlanAction;
use App\Domain\Planning\Actions\ExecuteShipmentPlanAction;
use App\Domain\Planning\Actions\ReconcileShipmentPlanAction;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Concrete slot implementations for ShipmentPlanResource pages.
 * Used by both ViewShipmentPlan and EditShipmentPlan for header parity.
 *
 * ShipmentPlan uses lifecycle actions (Confirm → Execute → Reconcile) instead
 * of generic status transitions — each is visible only when the plan is in
 * the right state, so they appear as a sequential workflow.
 */
trait ShipmentPlanHeaderActions
{
    protected function primaryLifecycleActions(): array
    {
        return [
            $this->confirmPlanAction(),
            $this->executePlanAction(),
            $this->reconcilePlanAction(),
        ];
    }

    protected function confirmPlanAction(): Action
    {
        return Action::make('confirmPlan')
            ->label(__('forms.labels.confirm_plan'))
            ->icon('heroicon-o-check-circle')
            ->color('warning')
            ->visible(fn () => $this->record->status === ShipmentPlanStatus::DRAFT)
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.confirm_shipment_plan'))
            ->modalDescription(__('messages.confirm_shipment_plan_description'))
            ->action(function () {
                try {
                    app(ConfirmShipmentPlanAction::class)->execute($this->record);

                    Notification::make()
                        ->title(__('messages.shipment_plan_confirmed'))
                        ->body(__('messages.payment_schedule_generated'))
                        ->success()
                        ->send();

                    if (method_exists($this, 'refreshFormData')) {
                        $this->refreshFormData(['status']);
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function executePlanAction(): Action
    {
        return Action::make('executePlan')
            ->label(__('forms.labels.create_shipment'))
            ->icon('heroicon-o-truck')
            ->color('success')
            ->visible(fn () => $this->record->status === ShipmentPlanStatus::CONFIRMED)
            ->disabled(fn () => $this->record->hasBlockingPayments())
            ->tooltip(fn () => $this->record->hasBlockingPayments()
                ? __('messages.blocking_payments_pending')
                : null)
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.create_shipment_from_plan'))
            ->modalDescription(__('messages.create_shipment_from_plan_description'))
            ->action(function () {
                try {
                    $shipment = app(ExecuteShipmentPlanAction::class)->execute($this->record);

                    Notification::make()
                        ->title(__('messages.shipment_created'))
                        ->body("Shipment {$shipment->reference} created successfully.")
                        ->success()
                        ->send();

                    if (method_exists($this, 'refreshFormData')) {
                        $this->refreshFormData(['status']);
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function reconcilePlanAction(): Action
    {
        return Action::make('reconcilePlan')
            ->label(__('forms.labels.reconcile'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->visible(fn () => $this->record->status === ShipmentPlanStatus::SHIPPED && $this->record->shipment_id)
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.reconcile_shipment_plan'))
            ->modalDescription(__('messages.reconcile_shipment_plan_description'))
            ->action(function () {
                try {
                    $adjustments = app(ReconcileShipmentPlanAction::class)->execute($this->record);

                    $count = count($adjustments);

                    Notification::make()
                        ->title(__('messages.reconciliation_complete'))
                        ->body($count > 0
                            ? "{$count} payment schedule items adjusted."
                            : 'No adjustments needed — planned and actual values match.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.action_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
