<?php

namespace App\Filament\Resources\PurchaseOrders\Concerns;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Domain\PurchaseOrders\Actions\SyncSupplierProductPricesAction;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Concrete slot implementations for PurchaseOrderResource pages.
 * Used by both ViewPurchaseOrder and EditPurchaseOrder for header parity.
 */
trait PurchaseOrderHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Generate PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->helperText('If checked, each line item will display the product photo and the filename will include "PIC".'),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'purchase_order_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Preview PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->live()
                        ->helperText('Preview the PDF with product photos in each line item.'),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'purchase_order_pdf',
                settingsKey: 'email_default_message_purchase_order',
                label: 'Send by Email',
            ),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function statusActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->transitionStatusAction(),
        ])
            ->label(__('forms.labels.status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->button();
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
                    $newStatus = PurchaseOrderStatus::from($data['new_status']);

                    $sideEffects = null;
                    if ($newStatus === PurchaseOrderStatus::CONFIRMED) {
                        $sideEffects = function ($po) {
                            app(SyncSupplierProductPricesAction::class)->execute($po);
                        };
                    }

                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        $newStatus,
                        $data['notes'] ?? null,
                        sideEffects: $sideEffects,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.$newStatus->getLabel())
                        ->success()
                        ->send();

                    if (method_exists($this, 'refreshFormData')) {
                        $this->refreshFormData(['status']);
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
