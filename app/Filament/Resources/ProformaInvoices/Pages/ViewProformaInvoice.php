<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CustomPricePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use App\Filament\Resources\ProformaInvoices\Widgets\ShipmentFulfillmentWidget;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProformaInvoiceStats::class,
            ShipmentFulfillmentWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->finalizeAction(),
            $this->reopenAction(),
            $this->generatePurchaseOrdersAction(),
            GeneratePdfAction::make(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'proforma_invoice_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Preview PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'proforma_invoice_pdf',
                label: 'Send by Email',
            ),
            $this->customPricePdfAction(),
            EditAction::make()
                ->visible(fn () => ! in_array($this->getRecord()->status, [
                    ProformaInvoiceStatus::FINALIZED,
                ])),
        ];
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

                $this->refreshFormData(['status']);
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

                $this->refreshFormData(['status']);
            });
    }

    protected function generatePurchaseOrdersAction(): Action
    {
        return Action::make('generatePurchaseOrders')
            ->label(__('forms.labels.generate_pos'))
            ->icon('heroicon-o-shopping-cart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Generate Purchase Orders')
            ->modalDescription(function () {
                $record = $this->getRecord();
                $record->loadMissing(['items.supplierCompany', 'items.product.suppliers']);

                // Resolve suppliers from product catalog for preview
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
                    $labels = collect($blockers)->pluck('label')->implode(', ');
                    return "**Cannot generate POs.** The following upfront payments must be resolved first:\n\n"
                        . $labels
                        . "\n\nPlease record and approve the required payments, or waive them to proceed.";
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
            ->modalSubmitActionLabel('Generate')
            ->visible(fn () => in_array($this->getRecord()->status, [
                    ProformaInvoiceStatus::CONFIRMED,
                    ProformaInvoiceStatus::REOPENED,
                ])
                && $this->getRecord()->items()->exists()
                && auth()->user()?->can('generate-purchase-orders'))
            ->action(function () {
                $record = $this->getRecord();

                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) > 0) {
                    Notification::make()
                        ->title(__('messages.blocked_by_payment'))
                        ->body(__('messages.resolve_payments_first'))
                        ->danger()
                        ->send();

                    return;
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

                Notification::make()
                    ->title($result->count() . ' ' . __('messages.pos_generated'))
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
            ])
            ->action(function (array $data) {
                try {
                    $template = new CustomPricePdfTemplate(
                        model: $this->getRecord(),
                        hideCommission: $data['hide_commission'] ?? false,
                    );
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
                    );

                    $content = $service->preview($template);

                    return response()->streamDownload(
                        function () use ($content) {
                            echo $content;
                        },
                        $template->getFilename(),
                        [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="' . $template->getFilename() . '"',
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
}
