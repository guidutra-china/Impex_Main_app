<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Enums\ProjectTeamRole;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->compareSupplierQuotationsAction(),
            $this->requestSupplierQuotationAction(),
            $this->createQuotationAction(),
            $this->createProformaInvoiceAction(),
            EditAction::make(),
        ];
    }

    protected function requestSupplierQuotationAction(): Action
    {
        return Action::make('requestSupplierQuotation')
            ->label(__('forms.labels.request_supplier_quotation'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->visible(fn () => in_array($this->record->status, [
                InquiryStatus::RECEIVED,
                InquiryStatus::QUOTING,
            ]))
            ->form([
                Select::make('company_ids')
                    ->label(__('forms.labels.suppliers'))
                    ->options(
                        fn () => Company::query()
                            ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->helperText(__('forms.helpers.select_one_or_more_suppliers_to_request_quotations_from')),
            ])
            ->action(function (array $data) {
                try {
                    $created = [];
                    $updated = [];
                    DB::transaction(function () use ($data, &$created, &$updated) {
                        $inquiry = Inquiry::lockForUpdate()->findOrFail($this->record->id);

                        foreach ($data['company_ids'] as $companyId) {
                            // Check if an RFQ already exists for this supplier with REQUESTED status
                            $existing = SupplierQuotation::where('inquiry_id', $inquiry->id)
                                ->where('company_id', $companyId)
                                ->where('status', SupplierQuotationStatus::REQUESTED)
                                ->first();

                            if ($existing) {
                                // Update existing: sync items from inquiry
                                $this->syncRfqItems($existing, $inquiry);
                                $updated[] = $existing->reference . ' (' . Company::find($companyId)->name . ')';
                            } else {
                                // Create new RFQ
                                $sq = SupplierQuotation::create([
                                    'inquiry_id' => $inquiry->id,
                                    'company_id' => $companyId,
                                    'status' => SupplierQuotationStatus::REQUESTED,
                                    'currency_code' => $inquiry->currency_code,
                                    'requested_at' => now()->toDateString(),
                                    'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::SOURCING)?->id
                                        ?? $inquiry->responsible_user_id,
                                ]);

                                foreach ($inquiry->items as $item) {
                                    SupplierQuotationItem::create([
                                        'supplier_quotation_id' => $sq->id,
                                        'inquiry_item_id' => $item->id,
                                        'product_id' => $item->product_id,
                                        'description' => $item->description,
                                        'quantity' => $item->quantity,
                                        'unit' => $item->unit,
                                        'unit_cost' => 0,
                                        'specifications' => $item->specifications,
                                        'notes' => $item->notes,
                                        'sort_order' => $item->sort_order,
                                    ]);
                                }

                                $created[] = $sq->reference . ' (' . Company::find($companyId)->name . ')';
                            }
                        }

                        if ($inquiry->status === InquiryStatus::RECEIVED) {
                            $all = array_merge($created, $updated);
                            app(TransitionStatusAction::class)->execute(
                                $inquiry,
                                InquiryStatus::QUOTING,
                                'Supplier quotations requested: ' . implode(', ', $all),
                            );
                        }
                    });

                    $parts = [];
                    if (! empty($created)) {
                        $parts[] = count($created) . ' created: ' . implode(', ', $created);
                    }
                    if (! empty($updated)) {
                        $parts[] = count($updated) . ' updated: ' . implode(', ', $updated);
                    }

                    Notification::make()
                        ->title((count($created) + count($updated)) . ' ' . __('messages.sq_created'))
                        ->body(implode("\n", $parts))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.error_creating_sq'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function syncRfqItems(SupplierQuotation $sq, Inquiry $inquiry): void
    {
        $existingItemsByInquiryItemId = $sq->items->keyBy('inquiry_item_id');
        $inquiryItemIds = $inquiry->items->pluck('id')->toArray();

        // Remove items no longer in the inquiry
        $sq->items()->whereNotIn('inquiry_item_id', $inquiryItemIds)->delete();

        // Add or update items
        foreach ($inquiry->items as $item) {
            $existing = $existingItemsByInquiryItemId->get($item->id);

            if ($existing) {
                // Update quantity, description, specs from inquiry (keep supplier's unit_cost)
                $existing->update([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'specifications' => $item->specifications,
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                ]);
            } else {
                SupplierQuotationItem::create([
                    'supplier_quotation_id' => $sq->id,
                    'inquiry_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_cost' => 0,
                    'specifications' => $item->specifications,
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                ]);
            }
        }
    }

    protected function createQuotationAction(): Action
    {
        $inquiry = $this->record;
        $hasSupplierQuotations = $inquiry->supplierQuotations()
            ->whereIn('status', [
                SupplierQuotationStatus::RECEIVED,
                SupplierQuotationStatus::UNDER_ANALYSIS,
                SupplierQuotationStatus::SELECTED,
            ])
            ->exists();

        return Action::make('createQuotation')
            ->label(__('forms.labels.create_quotation'))
            ->icon('heroicon-o-document-plus')
            ->color('success')
            ->visible(fn () => in_array($this->record->status, [
                InquiryStatus::RECEIVED,
                InquiryStatus::QUOTING,
                InquiryStatus::QUOTED,
            ]))
            ->form(function () use ($inquiry, $hasSupplierQuotations) {
                $fields = [];

                if ($hasSupplierQuotations) {
                    $sqOptions = $inquiry->supplierQuotations()
                        ->whereIn('status', [
                            SupplierQuotationStatus::RECEIVED,
                            SupplierQuotationStatus::UNDER_ANALYSIS,
                            SupplierQuotationStatus::SELECTED,
                        ])
                        ->with('company')
                        ->get()
                        ->mapWithKeys(fn ($sq) => [
                            $sq->id => "{$sq->reference} — {$sq->company->name} ({$sq->status->getLabel()})",
                        ])
                        ->toArray();

                    $fields[] = Placeholder::make('info')
                        ->content('Supplier quotations are available for this inquiry. Select which ones to use as price source. Items will be matched by product.')
                        ->columnSpanFull();

                    $fields[] = Select::make('supplier_quotation_ids')
                        ->label(__('forms.labels.source_supplier_quotations'))
                        ->options($sqOptions)
                        ->multiple()
                        ->required()
                        ->helperText(__('forms.helpers.select_one_or_more_supplier_quotations_for_each_product_the'));
                } else {
                    $fields[] = Placeholder::make('info')
                        ->content('No supplier quotations available. Items will be created with zero cost. You can fill prices manually in the quotation.')
                        ->columnSpanFull();
                }

                $fields[] = Select::make('commission_type')
                    ->label(__('forms.labels.commission_type'))
                    ->options(CommissionType::class)
                    ->default(CommissionType::EMBEDDED->value)
                    ->required();

                $fields[] = TextInput::make('commission_rate')
                    ->label(__('forms.labels.default_commission_rate'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->default(10)
                    ->helperText(__('forms.helpers.applied_to_items_where_the_client_has_no_catalog_price'));

                return $fields;
            })
            ->action(function (array $data) use ($hasSupplierQuotations) {
                try {
                    $quotation = DB::transaction(function () use ($data, $hasSupplierQuotations) {
                        $inquiry = Inquiry::with(['items.product.clients', 'items.product.suppliers'])
                            ->lockForUpdate()
                            ->findOrFail($this->record->id);

                        $commissionType = $data['commission_type'] instanceof CommissionType
                            ? $data['commission_type']
                            : CommissionType::from($data['commission_type']);
                        $commissionRate = (float) ($data['commission_rate'] ?? 0);

                        $quotation = Quotation::create([
                            'inquiry_id' => $inquiry->id,
                            'company_id' => $inquiry->company_id,
                            'contact_id' => $inquiry->contact_id,
                            'status' => QuotationStatus::DRAFT,
                            'currency_code' => $inquiry->currency_code,
                            'commission_type' => $commissionType,
                            'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                            'notes' => $inquiry->notes,
                            'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::SALES)?->id
                                ?? $inquiry->responsible_user_id,
                        ]);

                        $sqItemsByProduct = collect();
                        if ($hasSupplierQuotations && ! empty($data['supplier_quotation_ids'])) {
                            $sqItemsByProduct = SupplierQuotationItem::query()
                                ->whereIn('supplier_quotation_id', $data['supplier_quotation_ids'])
                                ->where('unit_cost', '>', 0)
                                ->with('supplierQuotation.company')
                                ->get()
                                ->groupBy('product_id');
                        }

                        $clientId = $inquiry->company_id;
                        $sortOrder = 0;

                        foreach ($inquiry->items as $inquiryItem) {
                            $productId = $inquiryItem->product_id;
                            $unitCost = 0;
                            $unitPrice = 0;
                            $selectedSupplierId = null;
                            $sqItemId = null;
                            $itemCommissionRate = 0;

                            $sqItems = $sqItemsByProduct->get($productId);
                            if ($sqItems && $sqItems->isNotEmpty()) {
                                $bestSqItem = $sqItems->first();
                                $unitCost = $bestSqItem->unit_cost;
                                $selectedSupplierId = $bestSqItem->supplierQuotation->company_id;
                                $sqItemId = $bestSqItem->id;
                            }

                            $clientPivot = null;
                            if ($productId && $inquiryItem->product) {
                                $clientPivot = $inquiryItem->product->clients()
                                    ->where('companies.id', $clientId)
                                    ->first()
                                    ?->pivot;
                            }

                            if ($clientPivot && $clientPivot->unit_price > 0) {
                                $unitPrice = $clientPivot->unit_price;
                            } elseif ($unitCost > 0 && $commissionType === CommissionType::EMBEDDED && $commissionRate > 0) {
                                $itemCommissionRate = $commissionRate;
                                $unitPrice = (int) round($unitCost * (1 + ($commissionRate / 100)));
                            } elseif ($unitCost > 0) {
                                $unitPrice = $unitCost;
                            }

                            if ($inquiryItem->target_price && $inquiryItem->target_price > 0 && $unitPrice === 0) {
                                $unitPrice = $inquiryItem->target_price;
                            }

                            QuotationItem::create([
                                'quotation_id' => $quotation->id,
                                'product_id' => $productId,
                                'supplier_quotation_item_id' => $sqItemId,
                                'quantity' => $inquiryItem->quantity,
                                'selected_supplier_id' => $selectedSupplierId,
                                'unit_cost' => $unitCost,
                                'commission_rate' => $itemCommissionRate,
                                'unit_price' => $unitPrice,
                                'notes' => $inquiryItem->specifications,
                                'sort_order' => $sortOrder++,
                            ]);
                        }

                        if ($inquiry->status === InquiryStatus::RECEIVED) {
                            app(TransitionStatusAction::class)->execute(
                                $inquiry,
                                InquiryStatus::QUOTING,
                                'Quotation ' . $quotation->reference . ' created from inquiry.',
                            );
                        }

                        return $quotation;
                    });

                    Notification::make()
                        ->title(__('messages.quotation_created') . ': ' . $quotation->reference)
                        ->body(__('messages.items_populated_redirecting'))
                        ->success()
                        ->send();

                    return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.error_creating_quotation'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function createProformaInvoiceAction(): Action
    {
        $existingPi = $this->record->proformaInvoices()
            ->where('status', '!=', ProformaInvoiceStatus::CANCELLED->value)
            ->latest()
            ->first();

        $isUpdate = (bool) $existingPi;

        return Action::make('createProformaInvoice')
            ->label($isUpdate
                ? __('forms.labels.update_proforma_invoice')
                : __('forms.labels.create_proforma_invoice'))
            ->icon('heroicon-o-document-text')
            ->color($isUpdate ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalHeading($isUpdate
                ? __('forms.labels.update_proforma_invoice') . ' — ' . $existingPi?->reference
                : 'Create Proforma Invoice')
            ->modalDescription($isUpdate
                ? __('messages.pi_update_description', ['reference' => $existingPi?->reference])
                : 'This will create a new Proforma Invoice linked to this inquiry. You can then import items from quotations or add them manually.')
            ->form([
                Select::make('quotation_ids')
                    ->label(__('forms.labels.link_quotations_optional'))
                    ->multiple()
                    ->default($isUpdate ? $existingPi?->quotations()->pluck('quotations.id')->toArray() : [])
                    ->options(function () {
                        $inquiry = $this->record;
                        return Quotation::query()
                            ->where(function ($query) use ($inquiry) {
                                $query->where('inquiry_id', $inquiry->id)
                                    ->orWhere(function ($q) use ($inquiry) {
                                        $q->whereNull('inquiry_id')
                                            ->where('company_id', $inquiry->company_id);
                                    });
                            })
                            ->whereNotIn('status', ['cancelled'])
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn ($q) => [
                                $q->id => $q->reference
                                    . ' — ' . ($q->company?->name ?? 'N/A')
                                    . ' (' . $q->status->getLabel() . ')'
                                    . ($q->inquiry_id === $inquiry->id ? '' : ' ⚠ different inquiry'),
                            ]);
                    })
                    ->helperText(__('forms.helpers.optionally_link_existing_quotations_items_can_be_imported')),
                Select::make('supplier_quotation_ids')
                    ->label('Link Supplier Quotations (Optional)')
                    ->multiple()
                    ->default($isUpdate ? $existingPi?->supplierQuotations()->pluck('supplier_quotations.id')->toArray() : [])
                    ->options(function () {
                        $inquiry = $this->record;
                        return SupplierQuotation::query()
                            ->where(function ($query) use ($inquiry) {
                                $query->where('inquiry_id', $inquiry->id)
                                    ->orWhere(function ($q) use ($inquiry) {
                                        $q->whereNull('inquiry_id')
                                            ->where('company_id', $inquiry->company_id);
                                    });
                            })
                            ->whereNotIn('status', [
                                SupplierQuotationStatus::REJECTED->value,
                                SupplierQuotationStatus::EXPIRED->value,
                            ])
                            ->with('company')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn ($sq) => [
                                $sq->id => $sq->reference
                                    . ' — ' . ($sq->company?->name ?? 'N/A')
                                    . ' (' . $sq->status->getLabel() . ')'
                                    . ($sq->inquiry_id === $inquiry->id ? '' : ' ⚠ different inquiry'),
                            ]);
                    })
                    ->helperText('Supplier quotations to use as cost source when importing items.'),
            ])
            ->action(function (array $data) use ($existingPi) {
                try {
                    $inquiry = $this->record;

                    $pi = DB::transaction(function () use ($inquiry, $data, $existingPi) {
                        if ($existingPi) {
                            // Update existing PI with current inquiry data
                            $existingPi->update([
                                'company_id' => $inquiry->company_id,
                                'contact_id' => $inquiry->contact_id,
                                'currency_code' => $inquiry->currency_code ?? 'USD',
                                'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::FINANCIAL)?->id
                                    ?? $inquiry->responsible_user_id,
                            ]);

                            $proformaInvoice = $existingPi;

                            // Sync quotation links
                            $proformaInvoice->quotations()->sync($data['quotation_ids'] ?? []);
                            $proformaInvoice->supplierQuotations()->sync($data['supplier_quotation_ids'] ?? []);

                            // Sync items from Inquiry
                            $this->syncPiItemsFromInquiry($proformaInvoice, $inquiry, $data['supplier_quotation_ids'] ?? []);
                        } else {
                            // Create new PI
                            $proformaInvoice = ProformaInvoice::create([
                                'inquiry_id' => $inquiry->id,
                                'company_id' => $inquiry->company_id,
                                'contact_id' => $inquiry->contact_id,
                                'status' => ProformaInvoiceStatus::DRAFT,
                                'currency_code' => $inquiry->currency_code ?? 'USD',
                                'issue_date' => now(),
                                'created_by' => auth()->id(),
                                'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::FINANCIAL)?->id
                                    ?? $inquiry->responsible_user_id,
                            ]);

                            if (! empty($data['quotation_ids'])) {
                                $proformaInvoice->quotations()->attach($data['quotation_ids']);
                            }

                            if (! empty($data['supplier_quotation_ids'])) {
                                $proformaInvoice->supplierQuotations()->attach($data['supplier_quotation_ids']);
                            }

                            // Import items from Inquiry
                            $this->syncPiItemsFromInquiry($proformaInvoice, $inquiry, $data['supplier_quotation_ids'] ?? []);
                        }

                        return $proformaInvoice;
                    });

                    Notification::make()
                        ->title($existingPi
                            ? __('messages.pi_updated') . ': ' . $pi->reference
                            : __('messages.pi_created') . ': ' . $pi->reference)
                        ->body(__('messages.redirect_import_quotations'))
                        ->success()
                        ->send();

                    return redirect(ProformaInvoiceResource::getUrl('edit', ['record' => $pi]));
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.error_creating_pi'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function syncPiItemsFromInquiry(ProformaInvoice $pi, Inquiry $inquiry, array $supplierQuotationIds = []): void
    {
        $inquiry->load('items.product.clients', 'items.product.specification');
        $existingPiItems = $pi->items()->get()->keyBy('product_id');
        $inquiryProductIds = $inquiry->items->pluck('product_id')->filter()->toArray();

        // Build supplier cost map from linked supplier quotations
        $supplierCostMap = [];
        if (! empty($supplierQuotationIds)) {
            $sqItems = SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->with('supplierQuotation')
                ->get();
            foreach ($sqItems as $sqItem) {
                if ($sqItem->product_id && ! isset($supplierCostMap[$sqItem->product_id])) {
                    $supplierCostMap[$sqItem->product_id] = [
                        'unit_cost' => $sqItem->unit_cost,
                        'supplier_company_id' => $sqItem->supplierQuotation->company_id ?? null,
                    ];
                }
            }
        }

        // Remove PI items no longer in Inquiry (only if no downstream dependencies)
        $pi->items()
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $inquiryProductIds)
            ->whereDoesntHave('purchaseOrderItem')
            ->whereDoesntHave('shipmentItems')
            ->whereDoesntHave('shipmentPlanItems')
            ->delete();

        // Add or update items
        $maxSort = 0;
        foreach ($inquiry->items as $item) {
            $maxSort++;
            $existing = $item->product_id ? $existingPiItems->get($item->product_id) : null;

            if ($existing) {
                // Update quantity, description, specs from inquiry (keep unit_price and unit_cost)
                $existing->update([
                    'product_id'     => $item->product_id,
                    'description'    => $item->product?->name ?? $item->description,
                    'specifications' => $item->product?->specification?->description ?? $item->specifications,
                    'quantity'       => $item->quantity,
                    'unit'           => $item->unit ?? 'pcs',
                    'notes'          => $item->notes,
                    'sort_order'     => $maxSort,
                ]);
            } else {
                // Get client-specific selling price
                $clientPrice = 0;
                if ($item->product) {
                    $clientPivot = $item->product->clients()
                        ->where('companies.id', $inquiry->company_id)
                        ->first()
                        ?->pivot;
                    if ($clientPivot && ($clientPivot->unit_price ?? 0) > 0) {
                        $clientPrice = $clientPivot->unit_price;
                    }
                }

                $supplierData = $supplierCostMap[$item->product_id] ?? null;

                ProformaInvoiceItem::create([
                    'proforma_invoice_id' => $pi->id,
                    'product_id'          => $item->product_id,
                    'quotation_item_id'   => null,
                    'supplier_company_id' => $supplierData['supplier_company_id'] ?? null,
                    'description'         => $item->product?->name ?? $item->description,
                    'specifications'      => $item->product?->specification?->description ?? $item->specifications,
                    'quantity'            => $item->quantity,
                    'unit'                => $item->unit ?? 'pcs',
                    'unit_price'          => $clientPrice,
                    'unit_cost'           => $supplierData['unit_cost'] ?? 0,
                    'incoterm'            => null,
                    'notes'               => $item->notes,
                    'sort_order'          => $maxSort,
                ]);
            }
        }
    }

    protected function compareSupplierQuotationsAction(): Action
    {
        return Action::make('compareSupplierQuotations')
            ->label(__('forms.labels.compare_supplier_quotations'))
            ->icon('heroicon-o-scale')
            ->color('info')
            ->visible(fn () => $this->record
                ->supplierQuotations()
                ->whereIn('status', [
                    SupplierQuotationStatus::RECEIVED,
                    SupplierQuotationStatus::UNDER_ANALYSIS,
                    SupplierQuotationStatus::SELECTED,
                ])
                ->exists()
            )
            ->url(fn () => InquiryResource::getUrl('compare-sq', ['record' => $this->record]));
    }
}
