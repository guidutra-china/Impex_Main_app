<?php

namespace App\Filament\Resources\Inquiries\Concerns;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Actions\AdvanceInquiryToQuotedAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Enums\ProjectTeamRole;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Actions\CreateQuotationCommissionCostsAction;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Quotations\Actions\CreateOrUpdateQuotationFromInquiryAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Actions\RevertInquiryStatusAction;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Concrete slot implementations for InquiryResource pages.
 * Used by both ViewInquiry and EditInquiry to guarantee header parity.
 */
trait InquiryHeaderActions
{
    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->requestSupplierQuotationAction(),
            $this->compareSupplierQuotationsAction(),
            $this->createQuotationAction(),
            $this->createQuotationNewVersionAction(),
            $this->createProformaInvoiceAction(),
        ])
            ->label(__('forms.labels.workflow'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->button();
    }

    private function latestQuotation(): ?Quotation
    {
        return $this->record->quotations()->latest('version')->first();
    }

    protected function statusActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->transitionStatusAction(),
            RevertInquiryStatusAction::make(),
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
                    $enum = InquiryStatus::from($status);

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
                        InquiryStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.InquiryStatus::from($data['new_status'])->getLabel())
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
                            $existing = SupplierQuotation::where('inquiry_id', $inquiry->id)
                                ->where('company_id', $companyId)
                                ->where('status', SupplierQuotationStatus::REQUESTED)
                                ->first();

                            if ($existing) {
                                $this->syncRfqItems($existing, $inquiry);
                                $updated[] = $existing->reference.' ('.Company::find($companyId)->name.')';
                            } else {
                                $sq = SupplierQuotation::create([
                                    'inquiry_id' => $inquiry->id,
                                    'description' => $inquiry->description,
                                    'company_id' => $companyId,
                                    'status' => SupplierQuotationStatus::REQUESTED,
                                    'currency_code' => $inquiry->currency_code,
                                    'requested_at' => now()->toDateString(),
                                    'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::SOURCING)?->id
                                        ?? $inquiry->responsible_user_id,
                                ]);

                                foreach ($inquiry->items as $item) {
                                    $unitCost = $this->resolveSupplierCost($item, $companyId);

                                    SupplierQuotationItem::create([
                                        'supplier_quotation_id' => $sq->id,
                                        'inquiry_item_id' => $item->id,
                                        'product_id' => $item->product_id,
                                        'description' => $item->description,
                                        'quantity' => $item->quantity,
                                        'unit' => $item->unit,
                                        'unit_cost' => $unitCost,
                                        'specifications' => $item->specifications,
                                        'notes' => $item->notes,
                                        'sort_order' => $item->sort_order,
                                    ]);
                                }

                                $created[] = $sq->reference.' ('.Company::find($companyId)->name.')';
                            }
                        }

                        if ($inquiry->status === InquiryStatus::RECEIVED) {
                            $all = array_merge($created, $updated);
                            app(TransitionStatusAction::class)->execute(
                                $inquiry,
                                InquiryStatus::QUOTING,
                                'Supplier quotations requested: '.implode(', ', $all),
                            );
                        }
                    });

                    $parts = [];
                    if (! empty($created)) {
                        $parts[] = count($created).' created: '.implode(', ', $created);
                    }
                    if (! empty($updated)) {
                        $parts[] = count($updated).' updated: '.implode(', ', $updated);
                    }

                    Notification::make()
                        ->title((count($created) + count($updated)).' '.__('messages.sq_created'))
                        ->body(implode("\n", $parts))
                        ->success()
                        ->send();

                    if (method_exists($this, 'refreshFormData')) {
                        $this->refreshFormData(['status']);
                    }
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

        $sq->items()->whereNotIn('inquiry_item_id', $inquiryItemIds)->delete();

        foreach ($inquiry->items as $item) {
            $existing = $existingItemsByInquiryItemId->get($item->id);

            if ($existing) {
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
                $unitCost = $this->resolveSupplierCost($item, $sq->company_id);

                SupplierQuotationItem::create([
                    'supplier_quotation_id' => $sq->id,
                    'inquiry_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_cost' => $unitCost,
                    'specifications' => $item->specifications,
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                ]);
            }
        }
    }

    protected function resolveSupplierCost($inquiryItem, int $supplierId): int
    {
        if (($inquiryItem->target_price ?? 0) > 0) {
            return $inquiryItem->target_price;
        }

        if ($inquiryItem->product_id) {
            $pivot = CompanyProduct::where('product_id', $inquiryItem->product_id)
                ->where('company_id', $supplierId)
                ->where('role', 'supplier')
                ->first();

            if ($pivot && ($pivot->unit_price ?? 0) > 0) {
                return $pivot->unit_price;
            }
        }

        return 0;
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

        $existingQuotation = $inquiry->quotations()
            ->whereNotIn('status', [QuotationStatus::CANCELLED->value, QuotationStatus::REJECTED->value, QuotationStatus::EXPIRED->value])
            ->latest()
            ->first();

        $isUpdate = (bool) $existingQuotation;

        return Action::make('createQuotation')
            ->label($isUpdate
                ? __('forms.labels.update_quotation')
                : __('forms.labels.create_quotation'))
            ->icon('heroicon-o-document-plus')
            ->color($isUpdate ? 'warning' : 'success')
            ->visible(fn () => in_array($this->record->status, [
                InquiryStatus::RECEIVED, InquiryStatus::QUOTING, InquiryStatus::QUOTED,
            ]) && (
                ! $this->latestQuotation() ||
                ! in_array($this->latestQuotation()->status, [QuotationStatus::SENT, QuotationStatus::NEGOTIATING], true)
            ))
            ->form(function () use ($inquiry, $hasSupplierQuotations) {
                $fields = [];

                // SQs ainda "Solicitadas" mas já com custos preenchidos não são
                // elegíveis como fonte de preço — avisar em vez de o seletor
                // simplesmente não aparecer e a quotation nascer com custo zero.
                $requestedWithCosts = $inquiry->supplierQuotations()
                    ->where('status', SupplierQuotationStatus::REQUESTED)
                    ->whereHas('items', fn ($q) => $q->where('unit_cost', '>', 0))
                    ->get()
                    ->map(fn ($sq) => $sq->reference);

                if ($requestedWithCosts->isNotEmpty()) {
                    $fields[] = Placeholder::make('requested_sqs_warning')
                        ->hiddenLabel()
                        ->content('⚠️ '.__('messages.requested_sqs_with_costs_warning', [
                            'refs' => $requestedWithCosts->join(', '),
                        ]))
                        ->columnSpanFull();
                }

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
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) use ($inquiry) {
                            $type = $get('commission_type');
                            $set('items_preview', $this->buildItemsPreview(
                                $inquiry,
                                $state ?? [],
                                $type ? (is_string($type) ? CommissionType::from($type) : $type) : null,
                                (float) ($get('commission_rate') ?? 0),
                            ));
                        })
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
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) use ($inquiry) {
                        $set('items_preview', $this->buildItemsPreview(
                            $inquiry,
                            $get('supplier_quotation_ids') ?? [],
                            $state ? (is_string($state) ? CommissionType::from($state) : $state) : null,
                            (float) ($get('commission_rate') ?? 0),
                        ));
                    });

                $fields[] = TextInput::make('commission_rate')
                    ->label(__('forms.labels.default_commission_rate'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->default(10)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, callable $get) use ($inquiry) {
                        $type = $get('commission_type');
                        $set('items_preview', $this->buildItemsPreview(
                            $inquiry,
                            $get('supplier_quotation_ids') ?? [],
                            $type ? (is_string($type) ? CommissionType::from($type) : $type) : null,
                            (float) ($state ?? 0),
                        ));
                    })
                    ->helperText(__('forms.helpers.applied_to_items_where_the_client_has_no_catalog_price'));

                $fields[] = Select::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->options(Incoterm::class)
                    ->placeholder(__('forms.placeholders.select_incoterm'))
                    ->helperText(__('forms.helpers.quotation_incoterm_shown_to_client'));

                $fields[] = Toggle::make('show_suppliers')
                    ->label(__('forms.labels.show_suppliers_to_client'))
                    ->default(false)
                    ->helperText('Se ativado, mostra os nomes reais dos fornecedores no PDF. Se desativado, anonimiza como "Supplier 1, 2, 3, ..." (ordenado do mais barato ao mais caro).');

                $fields[] = \Filament\Forms\Components\Repeater::make('items_preview')
                    ->label(__('forms.labels.items_preview'))
                    ->default(fn () => $this->buildItemsPreview(
                        $inquiry,
                        [],
                        CommissionType::EMBEDDED,
                        10.0,
                    ))
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('product_label')
                            ->label(__('forms.labels.product'))->disabled()->dehydrated(false),
                        \Filament\Forms\Components\TextInput::make('quantity')
                            ->label(__('forms.labels.quantity'))->numeric()->required(),
                        \Filament\Forms\Components\TextInput::make('source_sq_label')
                            ->label(__('forms.labels.source_sq'))->disabled()->dehydrated(false),
                        \Filament\Forms\Components\TextInput::make('unit_cost')
                            ->label(__('forms.labels.source_unit_cost'))->numeric()->step(0.0001),
                        \Filament\Forms\Components\Select::make('cost_currency_code')
                            ->label(__('forms.labels.cost_currency'))
                            ->options(\App\Domain\Settings\Models\Currency::query()->where('is_active', true)->pluck('code', 'code'))
                            ->required(),
                        \Filament\Forms\Components\TextInput::make('cost_exchange_rate')
                            ->label(__('forms.labels.cost_fx_rate'))->numeric()->step(0.00000001)
                            ->required()->minValue(0.00000001)
                            ->helperText(fn () => __('forms.helpers.fx_rate_captured_on', [
                                'date' => now()->format('d/m/Y'),
                            ])),
                        \Filament\Forms\Components\TextInput::make('commission_rate')
                            ->label(__('forms.labels.commission_pct'))->numeric()->step(0.01)->suffix('%'),
                        \Filament\Forms\Components\TextInput::make('unit_price')
                            ->label(__('forms.labels.unit_price'))->numeric()->step(0.0001)->required(),
                    ])
                    ->columns(4)
                    ->defaultItems(0)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false);

                return $fields;
            })
            ->action(function (array $data) use ($existingQuotation) {
                try {
                    $commissionType = $data['commission_type'] instanceof CommissionType
                        ? $data['commission_type']
                        : CommissionType::from($data['commission_type']);
                    $commissionRate = (float) ($data['commission_rate'] ?? 0);
                    $supplierQuotationIds = $data['supplier_quotation_ids'] ?? [];

                    $itemOverrides = [];
                    foreach (($data['items_preview'] ?? []) as $idx => $row) {
                        $inquiryItemId = $this->record->items[$idx]->id ?? null;
                        if ($inquiryItemId) {
                            $itemOverrides[$inquiryItemId] = $row;
                        }
                    }

                    $incoterm = $data['incoterm'] ?? null;
                    if ($incoterm !== null && ! $incoterm instanceof Incoterm) {
                        $incoterm = Incoterm::tryFrom((string) $incoterm);
                    }

                    $quotation = app(CreateOrUpdateQuotationFromInquiryAction::class)
                        ->execute(
                            inquiry: Inquiry::with('items')->findOrFail($this->record->id),
                            supplierQuotationIds: $supplierQuotationIds,
                            commissionType: $commissionType,
                            commissionRate: $commissionRate,
                            showSuppliers: (bool) ($data['show_suppliers'] ?? false),
                            itemOverrides: $itemOverrides,
                            incoterm: $incoterm,
                        );

                    if ($this->record->status === InquiryStatus::RECEIVED) {
                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            InquiryStatus::QUOTING,
                            'Quotation '.$quotation->reference.' created from inquiry.',
                        );
                    }

                    Notification::make()
                        ->title(($existingQuotation ? __('messages.quotation_updated') : __('messages.quotation_created')).': '.$quotation->reference)
                        ->body(__('messages.items_populated_redirecting'))
                        ->success()
                        ->send();

                    return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                } catch (QuotationLockedException $e) {
                    Notification::make()
                        ->title(__('messages.quotation_locked'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.error_creating_quotation'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function createQuotationNewVersionAction(): Action
    {
        return Action::make('createQuotationNewVersion')
            ->label(__('forms.labels.create_quotation_new_version'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => $this->latestQuotation()
                && in_array($this->latestQuotation()->status, [
                    QuotationStatus::SENT, QuotationStatus::NEGOTIATING,
                ], true))
            ->requiresConfirmation()
            ->action(function () {
                try {
                    $latest = $this->latestQuotation();
                    $supplierQuotationIds = $latest->items
                        ->pluck('supplier_quotation_item_id')
                        ->filter()
                        ->map(fn ($id) => SupplierQuotationItem::find($id)?->supplier_quotation_id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->toArray();

                    $quotation = app(CreateOrUpdateQuotationFromInquiryAction::class)
                        ->execute(
                            inquiry: Inquiry::with('items')->findOrFail($this->record->id),
                            supplierQuotationIds: $supplierQuotationIds,
                            commissionType: $latest->commission_type,
                            commissionRate: (float) $latest->commission_rate,
                            showSuppliers: (bool) $latest->show_suppliers,
                            forceNewVersion: true,
                            incoterm: $latest->incoterm,
                        );

                    Notification::make()
                        ->title(__('messages.quotation_new_version_created').': v'.$quotation->version)
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

    private function buildItemsPreview(
        Inquiry $inquiry,
        array $supplierQuotationIds,
        ?CommissionType $commissionType = null,
        float $commissionRate = 0,
    ): array {
        $resolver = app(\App\Domain\Settings\Services\CurrencyExchangeResolver::class);
        $sqItemsByProduct = empty($supplierQuotationIds)
            ? collect()
            : SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->with('supplierQuotation.company')
                ->get()
                ->groupBy('product_id');

        $rows = [];
        foreach ($inquiry->items as $inquiryItem) {
            $alts = $sqItemsByProduct->get($inquiryItem->product_id, collect());
            $primary = $alts->sortBy(function ($alt) use ($resolver, $inquiry) {
                $r = $resolver->resolve($alt->supplierQuotation->currency_code, $inquiry->currency_code);

                return $alt->unit_cost * $r['rate'];
            })->first();

            $sourceCurrency = $primary?->supplierQuotation?->currency_code ?? $inquiry->currency_code;
            $resolved = $resolver->resolve($sourceCurrency, $inquiry->currency_code);

            $unitCostMinor = $primary?->unit_cost ?? 0;
            $rate = (float) $resolved['rate'];
            $convertedCostMinor = (int) round($unitCostMinor * $rate);
            $itemCommissionRate = $commissionType === CommissionType::EMBEDDED ? $commissionRate : 0;
            $unitPriceMinor = $itemCommissionRate > 0
                ? (int) round($convertedCostMinor * (1 + $itemCommissionRate / 100))
                : $convertedCostMinor;

            $rows[] = [
                'product_label' => $inquiryItem->product?->name ?? '—',
                'quantity' => $inquiryItem->quantity,
                'source_sq_label' => $primary?->supplierQuotation?->reference
                    .($primary ? ' · '.$primary->supplierQuotation->company->name : ''),
                'unit_cost' => $unitCostMinor / 10000,
                'cost_currency_code' => $resolved['currency'],
                'cost_exchange_rate' => $rate,
                'commission_rate' => $itemCommissionRate,
                'unit_price' => $unitPriceMinor / 10000,
            ];
        }

        return $rows;
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
                ? __('forms.labels.update_proforma_invoice').' — '.$existingPi?->reference
                : 'Create Proforma Invoice')
            ->modalDescription($isUpdate
                ? __('messages.pi_update_description', ['reference' => $existingPi?->reference])
                : 'This will create a new Proforma Invoice linked to this inquiry. You can then import items from quotations or add them manually.')
            ->form([
                Select::make('quotation_ids')
                    ->label(__('forms.labels.link_quotations_optional'))
                    ->multiple()
                    ->default(function () use ($isUpdate, $existingPi) {
                        if ($isUpdate) {
                            return $existingPi?->quotations()->pluck('quotations.id')->toArray();
                        }

                        // Pré-seleciona a última versão da quotation desta inquiry.
                        $latest = Quotation::query()
                            ->where('inquiry_id', $this->record->id)
                            ->whereNotIn('status', ['cancelled'])
                            ->orderByDesc('version')
                            ->orderByDesc('id')
                            ->first();

                        return $latest ? [$latest->id] : [];
                    })
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
                                    .' — '.($q->company?->name ?? 'N/A')
                                    .' ('.$q->status->getLabel().')'
                                    .($q->inquiry_id === $inquiry->id ? '' : ' ⚠ different inquiry'),
                            ]);
                    })
                    ->helperText(__('forms.helpers.optionally_link_existing_quotations_items_can_be_imported')),
                Select::make('supplier_quotation_ids')
                    ->label('Link Supplier Quotations (Optional)')
                    ->multiple()
                    ->default(function () use ($isUpdate, $existingPi) {
                        if ($isUpdate) {
                            return $existingPi?->supplierQuotations()->pluck('supplier_quotations.id')->toArray();
                        }

                        // Pré-seleciona as SQs válidas desta inquiry (fonte de custo).
                        return SupplierQuotation::query()
                            ->where('inquiry_id', $this->record->id)
                            ->whereNotIn('status', [
                                SupplierQuotationStatus::REJECTED->value,
                                SupplierQuotationStatus::EXPIRED->value,
                            ])
                            ->pluck('id')
                            ->toArray();
                    })
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
                                    .' — '.($sq->company?->name ?? 'N/A')
                                    .' ('.$sq->status->getLabel().')'
                                    .($sq->inquiry_id === $inquiry->id ? '' : ' ⚠ different inquiry'),
                            ]);
                    })
                    ->helperText('Supplier quotations to use as cost source when importing items.'),
            ])
            ->action(function (array $data) use ($existingPi) {
                try {
                    $inquiry = $this->record;

                    $pi = DB::transaction(function () use ($inquiry, $data, $existingPi) {
                        if ($existingPi) {
                            $existingPi->update([
                                'company_id' => $inquiry->company_id,
                                'contact_id' => $inquiry->contact_id,
                                'currency_code' => $inquiry->currency_code ?? 'USD',
                                'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::FINANCIAL)?->id
                                    ?? $inquiry->responsible_user_id,
                            ]);

                            $proformaInvoice = $existingPi;

                            $proformaInvoice->quotations()->sync($data['quotation_ids'] ?? []);
                            $proformaInvoice->supplierQuotations()->sync($data['supplier_quotation_ids'] ?? []);

                            $this->syncPiItemsFromInquiry($proformaInvoice, $inquiry, $data['supplier_quotation_ids'] ?? []);
                        } else {
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

                            $this->syncPiItemsFromInquiry($proformaInvoice, $inquiry, $data['supplier_quotation_ids'] ?? []);
                        }

                        app(CreateQuotationCommissionCostsAction::class)
                            ->execute($proformaInvoice, $data['quotation_ids'] ?? []);

                        return $proformaInvoice;
                    });

                    app(AdvanceInquiryToQuotedAction::class)->execute($inquiry);

                    Notification::make()
                        ->title($existingPi
                            ? __('messages.pi_updated').': '.$pi->reference
                            : __('messages.pi_created').': '.$pi->reference)
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
                        'currency_code' => $sqItem->supplierQuotation->currency_code ?? null,
                    ];
                }
            }
        }

        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);

        $pi->items()
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $inquiryProductIds)
            ->whereDoesntHave('purchaseOrderItem')
            ->whereDoesntHave('shipmentItems')
            ->whereDoesntHave('shipmentPlanItems')
            ->delete();

        $maxSort = 0;
        foreach ($inquiry->items as $item) {
            $maxSort++;
            $existing = $item->product_id ? $existingPiItems->get($item->product_id) : null;

            if ($existing) {
                $existing->update([
                    'product_id' => $item->product_id,
                    'description' => $item->product?->name ?? $item->description,
                    'specifications' => $item->product?->specification?->description ?? $item->specifications,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit ?? 'pcs',
                    'notes' => $item->notes,
                    'sort_order' => $maxSort,
                ]);
            } else {
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

                $resolved = $resolver->resolve(
                    $supplierData['currency_code'] ?? null,
                    $pi->currency_code,
                    $pi->issue_date?->toDateString(),
                );

                ProformaInvoiceItem::create([
                    'proforma_invoice_id' => $pi->id,
                    'product_id' => $item->product_id,
                    'quotation_item_id' => null,
                    'supplier_company_id' => $supplierData['supplier_company_id'] ?? null,
                    'description' => $item->product?->name ?? $item->description,
                    'specifications' => $item->product?->specification?->description ?? $item->specifications,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit ?? 'pcs',
                    'unit_price' => $clientPrice,
                    'unit_cost' => $supplierData['unit_cost'] ?? 0,
                    'cost_currency_code' => $resolved['currency'],
                    'cost_exchange_rate' => $resolved['rate'],
                    'incoterm' => null,
                    'notes' => $item->notes,
                    'sort_order' => $maxSort,
                ]);
            }
        }
    }
}
