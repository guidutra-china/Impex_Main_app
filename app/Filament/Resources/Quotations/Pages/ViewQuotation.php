<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->convertToProformaInvoiceAction(),
            GeneratePdfAction::make(
                templateClass: QuotationPdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'quotation_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: QuotationPdfTemplate::class,
                label: 'Preview PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'quotation_pdf',
                label: 'Send by Email',
            ),
            EditAction::make(),
        ];
    }

    protected function convertToProformaInvoiceAction(): Action
    {
        return Action::make('convertToProformaInvoice')
            ->label(__('forms.labels.convert_to_pi'))
            ->icon('heroicon-o-document-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.convert_to_pi'))
            ->modalDescription(__('forms.helpers.convert_quotation_to_pi_description'))
            ->modalSubmitActionLabel(__('forms.labels.create_proforma_invoice'))
            ->visible(fn () => auth()->user()?->can('create-proforma-invoices')
                && $this->record->items()->count() > 0)
            ->action(function () {
                try {
                    $quotation = $this->record;

                    $pi = DB::transaction(function () use ($quotation) {
                        $proformaInvoice = ProformaInvoice::create([
                            'inquiry_id' => $quotation->inquiry_id,
                            'company_id' => $quotation->company_id,
                            'contact_id' => $quotation->contact_id,
                            'payment_term_id' => $quotation->payment_term_id,
                            'status' => ProformaInvoiceStatus::DRAFT,
                            'currency_code' => $quotation->currency_code ?? 'USD',
                            'issue_date' => now(),
                            'validity_days' => $quotation->validity_days,
                            'created_by' => auth()->id(),
                            'responsible_user_id' => $quotation->responsible_user_id,
                        ]);

                        $proformaInvoice->quotations()->attach($quotation->id);

                        $quotation->load('items.product.suppliers');
                        $sortOrder = 0;

                        $isSeparateCommission = $quotation->commission_type === CommissionType::SEPARATE;

                        foreach ($quotation->items as $item) {
                            $supplierId = $item->selected_supplier_id;

                            if (! $supplierId && $item->product) {
                                $preferred = $item->product->suppliers()
                                    ->orderByDesc('company_product.is_preferred')
                                    ->first();
                                $supplierId = $preferred?->id;
                            }

                            // When commission is separate, PI unit_price = unit_cost (no markup)
                            // The commission will be added as an AdditionalCost line
                            $unitPrice = $isSeparateCommission ? $item->unit_cost : $item->unit_price;

                            ProformaInvoiceItem::create([
                                'proforma_invoice_id' => $proformaInvoice->id,
                                'product_id' => $item->product_id,
                                'quotation_item_id' => $item->id,
                                'supplier_company_id' => $supplierId,
                                'description' => $item->product?->name,
                                'specifications' => $item->product?->specification?->description ?? null,
                                'quantity' => $item->quantity,
                                'unit' => 'pcs',
                                'unit_price' => $unitPrice,
                                'unit_cost' => $item->unit_cost,
                                'incoterm' => $item->incoterm,
                                'notes' => $item->notes,
                                'sort_order' => ++$sortOrder,
                            ]);
                        }

                        $this->createCommissionCost($proformaInvoice, $quotation);

                        return $proformaInvoice;
                    });

                    Notification::make()
                        ->title(__('messages.pi_created') . ': ' . $pi->reference)
                        ->success()
                        ->send();

                    return redirect(ProformaInvoiceResource::getUrl('edit', ['record' => $pi]));
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(__('messages.error_creating_pi'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function createCommissionCost(ProformaInvoice $pi, $quotation): void
    {
        if ($quotation->commission_type !== CommissionType::SEPARATE) {
            return;
        }

        if (! $quotation->commission_rate || $quotation->commission_rate <= 0) {
            return;
        }

        $itemsTotal = $pi->items()
            ->whereHas('quotationItem', fn ($q) => $q->where('quotation_id', $quotation->id))
            ->get()
            ->sum(fn ($item) => $item->line_total);

        if ($itemsTotal <= 0) {
            return;
        }

        $commissionAmount = (int) round($itemsTotal * ($quotation->commission_rate / 100));

        AdditionalCost::create([
            'costable_type' => $pi->getMorphClass(),
            'costable_id' => $pi->id,
            'cost_type' => AdditionalCostType::COMMISSION,
            'description' => 'Service Fee (' . $quotation->commission_rate . '%) — ' . $quotation->reference,
            'amount' => $commissionAmount,
            'currency_code' => $pi->currency_code,
            'exchange_rate' => 1,
            'amount_in_document_currency' => $commissionAmount,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
            'status' => AdditionalCostStatus::PENDING,
            'notes' => 'Auto-generated from ' . $quotation->reference . ' (Separate commission ' . $quotation->commission_rate . '%)',
        ]);
    }
}
