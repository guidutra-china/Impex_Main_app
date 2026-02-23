<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
            EditAction::make(),
        ];
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
            ->visible(fn () => $this->getRecord()->items()->exists())
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
