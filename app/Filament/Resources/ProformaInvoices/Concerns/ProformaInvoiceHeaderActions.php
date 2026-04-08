<?php

namespace App\Filament\Resources\ProformaInvoices\Concerns;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CustomPricePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\ProformaInvoices\Actions\CancelProformaInvoiceAction;
use App\Domain\ProformaInvoices\Actions\SyncClientProductPricesAction;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Concrete slot implementations for ProformaInvoiceResource pages.
 * Used by both ViewProformaInvoice and EditProformaInvoice for header parity.
 */
trait ProformaInvoiceHeaderActions
{
    protected function primaryLifecycleActions(): array
    {
        return [
            $this->finalizeAction(),
            $this->reopenAction(),
        ];
    }

    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Generate PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->helperText('If checked, each line item will display the product photo and the filename will include "PIC".'),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'proforma_invoice_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Preview PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->live()
                        ->helperText('Preview the PDF with product photos in each line item.'),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'proforma_invoice_pdf',
                label: 'Send by Email',
            ),
            $this->customPricePdfAction(),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->generatePurchaseOrdersAction(),
        ])
            ->label(__('forms.labels.workflow'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
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
                    $enum = ProformaInvoiceStatus::from($status);
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
                    $newStatus = ProformaInvoiceStatus::from($data['new_status']);

                    if ($newStatus === ProformaInvoiceStatus::CANCELLED) {
                        app(CancelProformaInvoiceAction::class)->execute(
                            $this->record,
                            $data['notes'] ?? null,
                        );
                    } else {
                        $sideEffects = null;

                        if ($newStatus === ProformaInvoiceStatus::CONFIRMED) {
                            $sideEffects = function ($pi) {
                                app(SyncClientProductPricesAction::class)->execute($pi);
                            };
                        }

                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            $newStatus,
                            $data['notes'] ?? null,
                            sideEffects: $sideEffects,
                        );
                    }

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . $newStatus->getLabel())
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

    protected function finalizeAction(): Action
    {
        return Action::make('finalize')
            ->label(__('forms.labels.finalize'))
            ->icon('heroicon-o-lock-closed')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Finalize Proforma Invoice')
            ->modalDescription(function () {
                $record = $this->getRecord();
                $blockers = $record->getFinalizationBlockers();

                if (! empty($blockers)) {
                    $list = collect($blockers)->map(fn ($b) => "• {$b}")->implode("\n");
                    return "**Cannot finalize.** The following conditions must be met first:\n\n{$list}";
                }

                return 'All conditions are met. This will lock the invoice — no further edits, payments, or additional costs can be added. Are you sure?';
            })
            ->modalSubmitActionLabel('Finalize')
            ->visible(fn () => in_array($this->getRecord()->status, [
                ProformaInvoiceStatus::CONFIRMED,
                ProformaInvoiceStatus::REOPENED,
            ]) && auth()->user()?->can('confirm-proforma-invoices'))
            ->action(function () {
                $record = $this->getRecord();
                $blockers = $record->getFinalizationBlockers();

                if (! empty($blockers)) {
                    Notification::make()
                        ->title(__('messages.cannot_finalize'))
                        ->body(__('messages.finalize_conditions_not_met'))
                        ->danger()
                        ->send();

                    return;
                }

                $targetStatus = ProformaInvoiceStatus::FINALIZED->value;

                if (! $record->canTransitionTo($targetStatus)) {
                    Notification::make()
                        ->title(__('messages.invalid_transition'))
                        ->body("Cannot transition from {$record->status->getLabel()} to Finalized.")
                        ->danger()
                        ->send();

                    return;
                }

                $record->transitionTo($targetStatus);

                Notification::make()
                    ->title(__('messages.invoice_finalized'))
                    ->body(__('messages.pi_locked'))
                    ->success()
                    ->send();

                if (method_exists($this, 'refreshFormData')) {
                    $this->refreshFormData(['status']);
                }
            });
    }

    protected function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('forms.labels.reopen'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reopen Proforma Invoice')
            ->modalDescription('This will unlock the invoice, allowing edits, new payments, and additional costs. This action should only be used when there is a divergence that needs to be resolved.')
            ->modalSubmitActionLabel('Reopen')
            ->visible(fn () => $this->getRecord()->status === ProformaInvoiceStatus::FINALIZED
                && auth()->user()?->can('reopen-proforma-invoices'))
            ->action(function () {
                $record = $this->getRecord();
                $targetStatus = ProformaInvoiceStatus::REOPENED->value;

                if (! $record->canTransitionTo($targetStatus)) {
                    Notification::make()
                        ->title(__('messages.invalid_transition'))
                        ->danger()
                        ->send();

                    return;
                }

                $record->transitionTo($targetStatus);

                Notification::make()
                    ->title(__('messages.invoice_reopened'))
                    ->body(__('messages.pi_unlocked'))
                    ->warning()
                    ->send();

                if (method_exists($this, 'refreshFormData')) {
                    $this->refreshFormData(['status']);
                }
            });
    }

    protected function generatePurchaseOrdersAction(): Action
    {
        return Action::make('generatePurchaseOrders')
            ->label(__('forms.labels.generate_pos'))
            ->icon('heroicon-o-shopping-cart')
            ->color('warning')
            ->modalHeading('Generate Purchase Orders')
            ->modalDescription(function () {
                $record = $this->getRecord();
                $record->loadMissing(['items.supplierCompany', 'items.product.suppliers']);

                foreach ($record->items as $item) {
                    if ($item->supplier_company_id === null && $item->product) {
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();
                        if ($preferred) {
                            $item->supplier_company_id = $preferred->id;
                        }
                    }
                }

                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) > 0) {
                    $labels = collect($blockers)->map(function ($item) {
                        $amount = number_format($item->amount / 10000, 2);
                        return "• {$item->label} — {$item->currency_code} {$amount}";
                    })->implode("\n");

                    if (auth()->user()?->can('override-payment-block')) {
                        return "**The following upfront payments are pending:**\n\n{$labels}\n\n"
                            . "Tick the box below to authorize PO generation anyway. The obligation will remain pending in the schedule.";
                    }

                    return "**Cannot generate POs.** The following upfront payments must be resolved first:\n\n{$labels}\n\n"
                        . "Use 'Request authorization' to ask a manager to bypass this check.";
                }

                $action = new GeneratePurchaseOrdersAction();
                $existing = $action->getExistingPOs($record);
                $skipped = $action->getSkippedSuppliers($record);

                $supplierGroups = $record->items
                    ->filter(fn ($item) => $item->supplier_company_id !== null)
                    ->groupBy('supplier_company_id');

                $newCount = $supplierGroups->count() - $existing->count();

                $lines = [];

                if ($newCount > 0) {
                    $lines[] = "**{$newCount} new PO(s)** will be created, one per supplier.";
                }

                if ($existing->isNotEmpty()) {
                    $updatable = $existing->filter(fn ($po) => in_array($po->status->value, ['draft', 'sent']));
                    $locked = $existing->filter(fn ($po) => ! in_array($po->status->value, ['draft', 'sent']));

                    if ($updatable->isNotEmpty()) {
                        $names = $updatable->map(fn ($po) => $po->reference . ' (' . ($po->supplierCompany?->name ?? 'N/A') . ')')->implode(', ');
                        $lines[] = "**{$updatable->count()} PO(s) will be updated:** {$names}";
                    }

                    if ($locked->isNotEmpty()) {
                        $names = $locked->map(fn ($po) => $po->reference . ' (' . $po->status->getLabel() . ')')->implode(', ');
                        $lines[] = "**Cannot update:** {$names} (already confirmed/shipped).";
                    }
                }

                if ($skipped->isNotEmpty()) {
                    $lines[] = "**{$skipped->count()} item(s)** have no supplier assigned and will be skipped.";
                }

                return implode("\n\n", $lines);
            })
            ->form(function () {
                $record = $this->getRecord();
                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) === 0) {
                    return [];
                }

                if (! auth()->user()?->can('override-payment-block')) {
                    return [];
                }

                return [
                    Checkbox::make('override_payment_block')
                        ->label(__('messages.override_payment_block'))
                        ->live()
                        ->default(false),
                    Textarea::make('override_reason')
                        ->label(__('messages.override_reason'))
                        ->rows(3)
                        ->visible(fn (Get $get) => (bool) $get('override_payment_block'))
                        ->requiredIf('override_payment_block', true)
                        ->minLength(10),
                ];
            })
            ->modalSubmitActionLabel('Generate')
            ->modalSubmitAction(function ($action) {
                $record = $this->getRecord();
                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                // Hide the submit button entirely when there are blockers and
                // the user cannot override — Branch B (Task 8) adds the
                // "Request authorization" extra action below.
                if (count($blockers) > 0 && ! auth()->user()?->can('override-payment-block')) {
                    return $action->hidden();
                }

                return $action;
            })
            ->visible(fn () => in_array($this->getRecord()->status, [
                    ProformaInvoiceStatus::CONFIRMED,
                    ProformaInvoiceStatus::REOPENED,
                ])
                && $this->getRecord()->items()->exists()
                && auth()->user()?->can('generate-purchase-orders'))
            ->action(function (array $data) {
                $record = $this->getRecord();

                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) > 0) {
                    if (! ($data['override_payment_block'] ?? false)) {
                        Notification::make()
                            ->title(__('messages.blocked_by_payment'))
                            ->body(__('messages.resolve_payments_first'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if (! auth()->user()?->can('override-payment-block')) {
                        Notification::make()
                            ->title(__('messages.blocked_by_payment'))
                            ->danger()
                            ->send();

                        return;
                    }

                    app(OverridePaymentBlocksAction::class)->execute(
                        $record,
                        $data['override_reason'] ?? '',
                    );
                }

                $action = new GeneratePurchaseOrdersAction();
                $result = $action->execute($record);

                if ($result->isEmpty()) {
                    Notification::make()
                        ->title(__('messages.no_pos_created'))
                        ->body(__('messages.all_pos_exist'))
                        ->warning()
                        ->send();

                    return;
                }

                $refs = $result->pluck('reference')->implode(', ');

                $title = ($data['override_payment_block'] ?? false)
                    ? __('messages.generated_with_payment_override')
                    : ($result->count() . ' ' . __('messages.pos_generated'));

                Notification::make()
                    ->title($title)
                    ->body($refs)
                    ->success()
                    ->send();
            });
    }

    protected function customPricePdfAction(): Action
    {
        return Action::make('customPricePdf')
            ->label('Custom Price PDF')
            ->icon('heroicon-o-document-currency-dollar')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->form([
                Checkbox::make('hide_commission')
                    ->label('Hide Service Fee')
                    ->helperText('If checked, the Service Fee line will not appear in the PDF.'),
                Checkbox::make('use_formula')
                    ->label('Apply price formula')
                    ->live()
                    ->helperText('Apply a formula to recalculate all unit prices (e.g. *0.70 for 30% discount).'),
                TextInput::make('price_formula')
                    ->label('Formula')
                    ->placeholder('e.g. *0.70, *1.15, +10, -5')
                    ->visible(fn (Get $get) => $get('use_formula'))
                    ->requiredIf('use_formula', true)
                    ->regex('/^[*\/+\-]\s*[0-9]*\.?[0-9]+$/')
                    ->helperText('Operators: * (multiply), / (divide), + (add), - (subtract). Value in major units for +/-.'),
                Checkbox::make('save_as_custom_price')
                    ->label('Save calculated prices as Custom Price')
                    ->visible(fn (Get $get) => $get('use_formula'))
                    ->helperText('If checked, the formula-calculated prices will be saved to each product\'s custom price for this client.'),
            ])
            ->action(function (array $data) {
                try {
                    $formula = ($data['use_formula'] ?? false) ? ($data['price_formula'] ?? null) : null;

                    if (($data['save_as_custom_price'] ?? false) && $formula) {
                        $this->saveFormulaAsCustomPrices($this->getRecord(), $formula);
                    }

                    $template = new CustomPricePdfTemplate(
                        model: $this->getRecord(),
                        hideCommission: $data['hide_commission'] ?? false,
                        priceFormula: $formula,
                    );
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
                    );

                    $document = $service->generate($template);

                    Notification::make()
                        ->title('Custom Price PDF Generated')
                        ->body("Version {$document->version} created: {$document->name}")
                        ->success()
                        ->send();

                    return response()->streamDownload(
                        function () use ($document) {
                            echo file_get_contents($document->getFullPath());
                        },
                        $document->name,
                        [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="' . $document->name . '"',
                        ],
                    );
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Custom Price PDF Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function saveFormulaAsCustomPrices($pi, string $formula): void
    {
        $pi->loadMissing('items.product');
        $clientId = $pi->company_id;

        if (! $clientId) {
            return;
        }

        foreach ($pi->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $calculatedPrice = CustomPricePdfTemplate::applyFormula($item->unit_price, $formula);

            CompanyProduct::updateOrCreate(
                [
                    'product_id' => $item->product_id,
                    'company_id' => $clientId,
                    'role' => 'client',
                ],
                [
                    'custom_price' => $calculatedPrice,
                ],
            );
        }
    }
}
