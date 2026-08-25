<?php

namespace App\Filament\Resources\PurchaseOrders\Concerns;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Financial\Actions\CreatePoDiscountAction;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Actions\SyncSupplierProductPricesAction;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Concerns\HasDocumentNamingOptions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Concrete slot implementations for PurchaseOrderResource pages.
 * Used by both ViewPurchaseOrder and EditPurchaseOrder for header parity.
 */
trait PurchaseOrderHeaderActions
{
    use HasDocumentNamingOptions;

    /**
     * Slot workflow do buildOperationsHeader: atalho de desconto do
     * fornecedor nesta PO (o custo vive na PI; o crédito ancora aqui).
     */
    protected function workflowActionGroup(): Action|ActionGroup|null
    {
        return $this->launchPoDiscountAction();
    }

    protected function launchPoDiscountAction(): Action
    {
        return Action::make('launchPoDiscount')
            ->label(__('forms.labels.launch_po_discount'))
            ->icon('heroicon-o-receipt-percent')
            ->color('warning')
            ->visible(fn () => $this->getRecord()->proforma_invoice_id !== null
                && auth()->user()?->can('create-payments'))
            ->form([
                Radio::make('discount_mode')
                    ->label(__('forms.labels.discount_mode'))
                    ->options([
                        'percent' => __('forms.labels.discount_mode_percent'),
                        'amount' => __('forms.labels.discount_mode_amount'),
                    ])
                    ->default('percent')
                    ->inline()
                    ->live(),
                TextInput::make('percent')
                    ->label(__('forms.labels.discount_percent'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->maxValue(100)
                    ->suffix('%')
                    ->visible(fn ($get) => $get('discount_mode') === 'percent')
                    ->required(fn ($get) => $get('discount_mode') === 'percent')
                    ->live(debounce: 400)
                    ->afterStateUpdated(function ($state, $set) {
                        if ((float) $state > 0) {
                            $calculated = Money::toMajor((int) round($this->getRecord()->total * ((float) $state / 100)));
                            $set('amount', number_format($calculated, 2, '.', ''));
                        }
                    }),
                TextInput::make('amount')
                    ->label(__('forms.labels.discount_amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->suffix(fn () => $this->getRecord()->currency_code)
                    ->helperText(fn () => __('forms.helpers.discount_over_po_total', [
                        'total' => $this->getRecord()->currency_code.' '.Money::format($this->getRecord()->total, 2),
                    ])),
                TextInput::make('description')
                    ->label(__('forms.labels.description'))
                    ->required()
                    ->maxLength(255)
                    ->default(fn () => 'Desconto '.$this->getRecord()->reference),
            ])
            ->action(function (array $data) {
                app(CreatePoDiscountAction::class)->execute(
                    $this->getRecord(),
                    Money::toMinor((float) $data['amount']),
                    $data['description'],
                    ($data['discount_mode'] ?? null) === 'percent' && ! empty($data['percent'])
                        ? (float) $data['percent']
                        : null,
                );

                Notification::make()
                    ->title(__('forms.labels.launch_po_discount'))
                    ->body(__('forms.helpers.discount_credit_anchored', ['po' => $this->getRecord()->reference]))
                    ->success()
                    ->send();
            });
    }

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
                    $this->documentNamingSection($this->namingPreferenceDefaults()),
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
                    $this->documentNamingSection($this->namingPreferenceDefaults()),
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

    /**
     * Fornecedor não tem conceito de filial — sem parent: aqui, diferente do
     * namingPreferenceDefaults() do Shipment.
     */
    protected function namingPreferenceDefaults(): NamingPreference
    {
        return NamingPreference::fromCompany($this->getRecord()?->supplierCompany);
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
