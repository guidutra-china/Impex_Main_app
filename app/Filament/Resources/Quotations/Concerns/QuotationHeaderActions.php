<?php

namespace App\Filament\Resources\Quotations\Concerns;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationVersion;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Concrete slot implementations for QuotationResource pages.
 * Used by both ViewQuotation and EditQuotation to guarantee header parity.
 */
trait QuotationHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: QuotationPdfTemplate::class,
                label: 'Generate PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->default(true)
                        ->helperText('If checked, each line item will display the product photo and the filename will include "PIC".'),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'quotation_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: QuotationPdfTemplate::class,
                label: 'Preview PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->default(true)
                        ->live()
                        ->helperText('Preview the PDF with product photos in each line item.'),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'quotation_pdf',
                settingsKey: 'email_default_message_quotation',
                label: 'Send by Email',
            ),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->convertToProformaInvoiceAction(),
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
            $this->createVersionAction(),
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
                    $enum = QuotationStatus::from($status);

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
                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        QuotationStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.QuotationStatus::from($data['new_status'])->getLabel())
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

    protected function createVersionAction(): Action
    {
        return Action::make('createVersion')
            ->label(__('forms.labels.save_version'))
            ->icon('heroicon-o-clock')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Save Version Snapshot')
            ->modalDescription('This will create a snapshot of the current quotation state. All items and pricing will be preserved.')
            ->form([
                Textarea::make('change_notes')
                    ->label(__('forms.labels.change_notes'))
                    ->placeholder(__('forms.placeholders.describe_what_changed_in_this_version'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data) {
                try {
                    $savedVersion = DB::transaction(function () use ($data) {
                        $quotation = Quotation::query()
                            ->lockForUpdate()
                            ->findOrFail($this->record->id);

                        $currentVersion = $quotation->version;

                        $snapshot = [
                            'quotation' => $quotation->toArray(),
                            'items' => $quotation->items()
                                ->with('suppliers')
                                ->get()
                                ->map(fn ($item) => array_merge($item->toArray(), [
                                    'suppliers' => $item->suppliers->toArray(),
                                ]))
                                ->toArray(),
                        ];

                        QuotationVersion::create([
                            'quotation_id' => $quotation->id,
                            'version' => $currentVersion,
                            'snapshot' => $snapshot,
                            'change_notes' => $data['change_notes'] ?? null,
                            'created_by' => auth()->id(),
                        ]);

                        $quotation->increment('version');

                        return $currentVersion;
                    });

                    Notification::make()
                        ->title(__('messages.version_saved', ['version' => $savedVersion]))
                        ->body(__('messages.snapshot_created', ['version' => $savedVersion + 1]))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Error creating version')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                if (method_exists($this, 'refreshFormData')) {
                    $this->refreshFormData(['version']);
                }
            });
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

                            // SEPARATE commission: PI unit_price equals cost in PI/quote currency,
                            // so use the converted cost (raw unit_cost is now in source currency).
                            $unitPrice = $isSeparateCommission ? $item->converted_unit_cost : $item->unit_price;

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
                                'cost_currency_code' => $item->cost_currency_code,
                                'cost_exchange_rate' => $item->cost_exchange_rate,
                                'incoterm' => $item->incoterm,
                                'notes' => $item->notes,
                                'sort_order' => ++$sortOrder,
                            ]);
                        }

                        $this->createCommissionCost($proformaInvoice, $quotation);

                        return $proformaInvoice;
                    });

                    Notification::make()
                        ->title(__('messages.pi_created').': '.$pi->reference)
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
            'description' => 'Service Fee ('.$quotation->commission_rate.'%) — '.$quotation->reference,
            'amount' => $commissionAmount,
            'currency_code' => $pi->currency_code,
            'exchange_rate' => 1,
            'amount_in_document_currency' => $commissionAmount,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
            'status' => AdditionalCostStatus::PENDING,
            'notes' => 'Auto-generated from '.$quotation->reference.' (Separate commission '.$quotation->commission_rate.'%)',
        ]);
    }
}
