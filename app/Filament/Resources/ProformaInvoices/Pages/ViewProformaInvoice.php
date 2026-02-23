<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProformaInvoiceStats::class,
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
            EditAction::make()
                ->visible(fn () => ! in_array($this->getRecord()->status, [
                    ProformaInvoiceStatus::FINALIZED,
                ])),
        ];
    }

    protected function finalizeAction(): Action
    {
        return Action::make('finalize')
            ->label('Finalize')
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
            ]))
            ->action(function () {
                $record = $this->getRecord();
                $blockers = $record->getFinalizationBlockers();

                if (! empty($blockers)) {
                    Notification::make()
                        ->title('Cannot Finalize')
                        ->body('Not all conditions are met. Check outstanding payments, additional costs, and shipments.')
                        ->danger()
                        ->send();

                    return;
                }

                $targetStatus = ProformaInvoiceStatus::FINALIZED->value;

                if (! $record->canTransitionTo($targetStatus)) {
                    Notification::make()
                        ->title('Invalid Transition')
                        ->body("Cannot transition from {$record->status->getLabel()} to Finalized.")
                        ->danger()
                        ->send();

                    return;
                }

                $record->transitionTo($targetStatus);

                Notification::make()
                    ->title('Invoice Finalized')
                    ->body('The proforma invoice has been locked.')
                    ->success()
                    ->send();

                $this->refreshFormData(['status']);
            });
    }

    protected function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
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
                        ->title('Invalid Transition')
                        ->danger()
                        ->send();

                    return;
                }

                $record->transitionTo($targetStatus);

                Notification::make()
                    ->title('Invoice Reopened')
                    ->body('The proforma invoice has been unlocked for modifications.')
                    ->warning()
                    ->send();

                $this->refreshFormData(['status']);
            });
    }

    protected function generatePurchaseOrdersAction(): Action
    {
        return Action::make('generatePurchaseOrders')
            ->label('Generate POs')
            ->icon('heroicon-o-shopping-cart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Generate Purchase Orders')
            ->modalDescription(function () {
                $record = $this->getRecord();
                $record->loadMissing(['items.supplierCompany']);

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
                    $names = $existing->map(fn ($po) => $po->supplierCompany?->name ?? $po->reference)->implode(', ');
                    $lines[] = "**Already exists:** {$names} (will be skipped).";
                }

                if ($skipped->isNotEmpty()) {
                    $lines[] = "**{$skipped->count()} item(s)** have no supplier assigned and will be skipped.";
                }

                if ($newCount <= 0) {
                    $lines[] = "All supplier POs already exist for this PI. Nothing to generate.";
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
                        ->title('Blocked by Payment Requirements')
                        ->body('Resolve upfront/deposit payments before generating POs.')
                        ->danger()
                        ->send();

                    return;
                }

                $action = new GeneratePurchaseOrdersAction();
                $created = $action->execute($record);

                if ($created->isEmpty()) {
                    Notification::make()
                        ->title('No POs Created')
                        ->body('All supplier POs already exist or no items have suppliers assigned.')
                        ->warning()
                        ->send();

                    return;
                }

                $refs = $created->pluck('reference')->implode(', ');

                Notification::make()
                    ->title($created->count() . ' Purchase Order(s) Generated')
                    ->body("Created: {$refs}")
                    ->success()
                    ->send();
            });
    }
}
