<?php

namespace App\Filament\RelationManagers;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Actions\WaiveAdditionalCostAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CostStatementPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AdditionalCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'additionalCosts';

    protected static ?string $title = 'Additional Costs';

    protected static BackedEnum|string|null $icon = 'heroicon-o-receipt-percent';

    /**
     * Detect if the given (possibly virtual-cloned) record represents the
     * forwarder side of a freight cost. The clone created in
     * expandSideRows() carries an `_is_forwarder_row` flag.
     */
    public function isForwarderRow(Model $record): bool
    {
        return (bool) ($record->_is_forwarder_row ?? false);
    }

    /**
     * Detect if the given (possibly virtual-cloned) record represents the
     * supplier-payable side of a cost. The clone created in
     * expandSideRows() carries an `_is_supplier_payable_row` flag.
     */
    public function isSupplierPayableRow(Model $record): bool
    {
        return (bool) ($record->_is_supplier_payable_row ?? false);
    }

    /**
     * Override Filament's record fetch so a single AdditionalCost with extra
     * payable sides renders as multiple virtual rows — the client-billable
     * side, plus a forwarder-payable side (freight on Shipments) and/or a
     * supplier-payable side (any owner). The underlying DB row is unchanged;
     * clones reuse the same id but carry a side flag and a side-specific
     * record key so Filament can tell them apart for actions and selection.
     */
    public function getTableRecords(): Paginator|CursorPaginator|EloquentCollection
    {
        $records = parent::getTableRecords();

        if ($records instanceof EloquentCollection) {
            return new EloquentCollection($this->expandSideRows($records));
        }

        $records->setCollection(
            collect($this->expandSideRows($records->getCollection()))
        );

        return $records;
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (! $record instanceof Model) {
            return parent::getTableRecordKey($record);
        }

        return $record->getKey().match (true) {
            $this->isForwarderRow($record) => '-forwarder',
            $this->isSupplierPayableRow($record) => '-supplier',
            default => '-client',
        };
    }

    /**
     * @param  iterable<int, Model>  $records
     * @return array<int, Model>
     */
    protected function expandSideRows(iterable $records): array
    {
        $expanded = [];

        foreach ($records as $record) {
            $expanded[] = $record;

            // Freight forwarder side — Shipment-owned costs only (unchanged).
            if ($this->getOwnerRecord() instanceof Shipment
                && ($record->forwarder_amount_in_document_currency || $record->forwarder_amount)) {
                $clone = clone $record;
                $clone->setAttribute('_is_forwarder_row', true);
                $clone->setRelations($record->getRelations());
                $expanded[] = $clone;
            }

            // Supplier payable side — any owner (PI or Shipment).
            if ($record->hasSupplierPayableSide()) {
                $clone = clone $record;
                $clone->setAttribute('_is_supplier_payable_row', true);
                $clone->setRelations($record->getRelations());
                $expanded[] = $clone;
            }
        }

        return $expanded;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cost_type')
                    ->label(__('forms.labels.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description),
                TextColumn::make('commission_rate')
                    ->label(__('forms.labels.commission_rate'))
                    ->formatStateUsing(fn ($state) => $state ? $state.'%' : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_mode')
                    ->label(__('forms.labels.commission_model'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('display_side')
                    ->label(__('forms.labels.side'))
                    ->badge()
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => 'forwarder',
                        $this->isSupplierPayableRow($record) => 'supplier',
                        default => 'client',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'forwarder' => __('forms.labels.side_forwarder'),
                        'supplier' => __('forms.labels.side_supplier'),
                        default => __('forms.labels.side_client'),
                    })
                    ->color(fn ($state) => match ($state) {
                        'forwarder' => 'info',
                        'supplier' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('display_amount')
                    ->label(__('forms.labels.amount'))
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => $record->forwarder_amount,
                        $this->isSupplierPayableRow($record) => $record->supplier_payable_amount,
                        default => $record->amount,
                    })
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->alignEnd(),
                TextColumn::make('display_currency')
                    ->label(__('forms.labels.currency'))
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => $record->forwarder_currency_code,
                        $this->isSupplierPayableRow($record) => $record->supplier_payable_currency_code,
                        default => $record->currency_code,
                    }),
                TextColumn::make('display_doc_amount')
                    ->label(__('forms.labels.doc_amount'))
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => $record->forwarder_amount_in_document_currency,
                        $this->isSupplierPayableRow($record) => $record->supplier_payable_amount_in_document_currency,
                        default => $record->amount_in_document_currency,
                    })
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->alignEnd(),
                TextColumn::make('display_party')
                    ->label(__('forms.labels.billable_to'))
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => $record->forwarderCompany?->name ?? '—',
                        $this->isSupplierPayableRow($record) => $record->supplierCompany?->name ?? '—',
                        default => $record->billable_to?->getLabel() ?? '—',
                    })
                    ->badge(),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_date')
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('display_status')
                    ->label(__('forms.labels.status'))
                    ->state(fn ($record) => match (true) {
                        $this->isForwarderRow($record) => $record->forwarder_status,
                        $this->isSupplierPayableRow($record) => $record->supplier_payable_status,
                        default => $record->status,
                    })
                    ->badge(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('cost_date', 'desc')
            ->filters([
                SelectFilter::make('cost_type')
                    ->options(AdditionalCostType::class)
                    ->label(__('forms.labels.type')),
                SelectFilter::make('billable_to')
                    ->options(BillableTo::class)
                    ->label(__('forms.labels.billable_to')),
                SelectFilter::make('status')
                    ->options(AdditionalCostStatus::class)
                    ->label(__('forms.labels.status')),
            ])
            ->headerActions([
                $this->addCostAction(),
                $this->costStatementAction(),
            ])
            ->recordActions([
                $this->editCostAction(),
                $this->waiveCostAction(),
                $this->deleteCostAction(),
            ]);
    }

    protected function addCostAction(): Action
    {
        return Action::make('addCost')
            ->label(__('forms.labels.add_cost'))
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('create-payments'))
            ->form($this->costFormSchema())
            ->action(function (array $data) {
                $cost = $this->saveCost($data);

                if ($this->isEmbeddedCommission($cost)) {
                    $this->applyEmbeddedCommission($cost);
                } else {
                    $this->syncScheduleItem($cost);
                }

                Notification::make()
                    ->title('Additional cost added')
                    ->success()
                    ->send();
            });
    }

    protected function editCostAction(): Action
    {
        return Action::make('edit')
            ->label(__('forms.labels.edit'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn ($record) => ! $this->isForwarderRow($record)
                && ! $this->isSupplierPayableRow($record)
                && $record->status !== AdditionalCostStatus::WAIVED
                && auth()->user()?->can('create-payments'))
            ->fillForm(fn ($record) => [
                'cost_type' => $record->cost_type->value,
                'commission_rate' => $record->commission_rate,
                'commission_mode' => $record->commission_mode?->value,
                'description' => $record->description,
                'amount' => Money::toMajor($record->isDiscount() ? abs($record->amount) : $record->amount),
                'currency_code' => $record->currency_code,
                'exchange_rate' => $record->exchange_rate,
                'billable_to' => $record->billable_to->value,
                'supplier_company_id' => $record->supplier_company_id,
                'forwarder_company_id' => $record->forwarder_company_id,
                'forwarder_amount' => $record->forwarder_amount ? Money::toMajor($record->forwarder_amount) : null,
                'forwarder_currency_code' => $record->forwarder_currency_code,
                'forwarder_exchange_rate' => $record->forwarder_exchange_rate,
                'has_supplier_payable' => $record->hasSupplierPayableSide(),
                'supplier_payable_amount' => $record->supplier_payable_amount ? Money::toMajor($record->supplier_payable_amount) : null,
                'supplier_payable_currency_code' => $record->supplier_payable_currency_code,
                'supplier_payable_exchange_rate' => $record->supplier_payable_exchange_rate,
                'supplier_payable_due_date' => $record->supplier_payable_due_date,
                'cost_date' => $record->cost_date,
                'notes' => $record->notes,
                'attachment_path' => $record->attachment_path,
            ])
            ->form($this->costFormSchema())
            ->action(function ($record, array $data) {
                $wasEmbedded = $this->isEmbeddedCommission($record);

                // If it was embedded before, revert the unit_price changes first
                if ($wasEmbedded) {
                    $this->revertEmbeddedCommission($record);
                }

                $cost = $this->saveCost($data, $record);

                if ($this->isEmbeddedCommission($cost)) {
                    $this->applyEmbeddedCommission($cost);
                    // Remove any existing schedule item since it's now embedded
                    PaymentScheduleItem::where('source_type', AdditionalCost::class)
                        ->where('source_id', $cost->id)
                        ->whereDoesntHave('allocations')
                        ->delete();
                } else {
                    $this->syncScheduleItem($cost);
                }

                Notification::make()
                    ->title('Cost updated')
                    ->success()
                    ->send();
            });
    }

    protected function waiveCostAction(): Action
    {
        return Action::make('waive')
            ->label(__('forms.labels.waive'))
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This will waive the cost and its linked schedule items. The amounts will no longer be collectible/deductible.')
            ->visible(fn ($record) => ! $this->isForwarderRow($record)
                && ! $this->isSupplierPayableRow($record)
                && in_array($record->status, [AdditionalCostStatus::PENDING, AdditionalCostStatus::INVOICED])
                && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                // Revert embedded commission before waiving
                if ($this->isEmbeddedCommission($record)) {
                    $this->revertEmbeddedCommission($record);
                }

                app(WaiveAdditionalCostAction::class)->execute($record, auth()->id());

                Notification::make()->title('Cost waived')->success()->send();
            });
    }

    protected function deleteCostAction(): Action
    {
        return Action::make('delete')
            ->label(__('forms.labels.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This will delete the cost and its linked schedule items.')
            ->visible(fn ($record) => ! $this->isForwarderRow($record)
                && ! $this->isSupplierPayableRow($record)
                && $record->status === AdditionalCostStatus::PENDING
                && auth()->user()?->can('create-payments'))
            ->action(function ($record) {
                $hasAllocations = PaymentScheduleItem::where('source_type', AdditionalCost::class)
                    ->where('source_id', $record->id)
                    ->whereHas('allocations')
                    ->exists();

                if ($hasAllocations) {
                    Notification::make()
                        ->title(__('forms.validation.cost_has_allocations_cannot_delete'))
                        ->danger()
                        ->send();

                    return;
                }

                // Revert embedded commission before deleting
                if ($this->isEmbeddedCommission($record)) {
                    $this->revertEmbeddedCommission($record);
                }

                PaymentScheduleItem::where('source_type', AdditionalCost::class)
                    ->where('source_id', $record->id)
                    ->whereDoesntHave('allocations')
                    ->delete();

                $record->delete();

                Notification::make()->title('Cost deleted')->danger()->send();
            });
    }

    protected function isCommissionType($get): bool
    {
        $val = $get('cost_type');

        if ($val instanceof AdditionalCostType) {
            return $val === AdditionalCostType::COMMISSION;
        }

        return $val === AdditionalCostType::COMMISSION->value
            || $val === 'commission';
    }

    protected function isDiscountType($get): bool
    {
        $val = $get('cost_type');

        if ($val instanceof AdditionalCostType) {
            return $val === AdditionalCostType::DISCOUNT;
        }

        return $val === AdditionalCostType::DISCOUNT->value || $val === 'discount';
    }

    /**
     * IDs (int, únicos) dos fornecedores "do processo": itens da PI, ou POs
     * dos itens do Shipment. Usado para priorizar o select de fornecedor.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function ownerSupplierIds(?Model $owner): \Illuminate\Support\Collection
    {
        if ($owner instanceof ProformaInvoice) {
            return $owner->items()
                ->whereNotNull('supplier_company_id')
                ->pluck('supplier_company_id')
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        if ($owner instanceof Shipment) {
            return PurchaseOrder::query()
                ->whereHas('items', function ($q) use ($owner) {
                    $q->whereIn('id', $owner->items()->whereNotNull('purchase_order_item_id')->select('purchase_order_item_id'));
                })
                ->pluck('supplier_company_id')
                ->filter()
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        return collect();
    }

    /**
     * Trocar o tipo entre desconto (crédito) e custo comum inverte a natureza
     * do PSI; com alocações registradas isso corromperia o histórico.
     */
    public static function assertCostTypeSwitchable(AdditionalCost $cost, AdditionalCostType|string|null $newType): void
    {
        if ($newType === null) {
            return;
        }

        $newValue = $newType instanceof AdditionalCostType ? $newType->value : $newType;
        $wasDiscount = $cost->cost_type === AdditionalCostType::DISCOUNT;
        $willBeDiscount = $newValue === AdditionalCostType::DISCOUNT->value;

        if ($wasDiscount === $willBeDiscount) {
            return;
        }

        $hasAllocations = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->where(function ($q) {
                $q->whereHas('allocations')->orWhereHas('creditAllocations');
            })
            ->exists();

        if ($hasAllocations) {
            throw ValidationException::withMessages([
                'cost_type' => __('forms.validation.cost_type_locked_by_allocations'),
            ]);
        }
    }

    protected function isSupplierBillable($get): bool
    {
        return $this->isSupplierBillableValue($get('billable_to'));
    }

    protected function isSupplierBillableValue(mixed $val): bool
    {
        if ($val instanceof BillableTo) {
            return $val === BillableTo::SUPPLIER;
        }

        return $val === BillableTo::SUPPLIER->value || $val === 'supplier';
    }

    protected function costFormSchema(): array
    {
        $isFreight = function ($get): bool {
            $val = $get('cost_type');

            if ($val instanceof AdditionalCostType) {
                return $val === AdditionalCostType::FREIGHT;
            }

            return $val === AdditionalCostType::FREIGHT->value
                || $val === 'freight';
        };

        $isCommission = fn ($get): bool => $this->isCommissionType($get);

        return [
            Section::make(__('forms.sections.cost_details'))->columns(2)->schema([
                Select::make('cost_type')
                    ->label(__('forms.labels.cost_type'))
                    ->options(AdditionalCostType::class)
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($get, $set) {
                        // COMPANY não é opção válida para desconto; limpa o
                        // valor obsoleto ao trocar o tipo para desconto.
                        if ($this->isDiscountType($get) && $get('billable_to') === BillableTo::COMPANY->value) {
                            $set('billable_to', BillableTo::CLIENT->value);
                        }
                    }),
                TextInput::make('description')
                    ->label(__('forms.labels.description'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('commission_mode')
                    ->label(__('forms.labels.commission_model'))
                    ->options(CommissionType::class)
                    ->required()
                    ->live()
                    ->visible(fn ($get) => $isCommission($get))
                    ->helperText(fn ($get) => match ($get('commission_mode')) {
                        CommissionType::EMBEDDED->value, CommissionType::EMBEDDED => __('forms.helpers.commission_embedded_in_the_unit_price'),
                        CommissionType::SEPARATE->value, CommissionType::SEPARATE => __('forms.helpers.commission_separate_creates_payment_schedule'),
                        default => __('forms.helpers.embedded_commission_per_item_separate_commission_on_total'),
                    }),
                TextInput::make('commission_rate')
                    ->label(__('forms.labels.commission_rate'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->maxValue(100)
                    ->suffix('%')
                    ->required()
                    ->visible(fn ($get) => $isCommission($get))
                    ->live(debounce: 400)
                    ->afterStateUpdated(function ($state, $get, $set) {
                        $owner = $this->getOwnerRecord();
                        if ($owner instanceof ProformaInvoice && $state > 0) {
                            $subtotal = $owner->subtotal;
                            $calculatedAmount = Money::toMajor((int) round($subtotal * ((float) $state / 100)));
                            $set('amount', number_format($calculatedAmount, 2, '.', ''));
                        }
                    }),
                TextInput::make('amount')
                    ->label(fn ($get) => $this->isDiscountType($get)
                        ? __('forms.labels.discount_amount')
                        : __('forms.labels.amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->helperText(fn ($get) => match (true) {
                        $this->isDiscountType($get) => __('forms.helpers.discount_amount'),
                        $isFreight($get) => __('forms.helpers.amount_charged_to_client'),
                        $isCommission($get) => __('forms.helpers.auto_calculated_from_commission_rate_editable'),
                        default => null,
                    }),
                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->default(fn () => $this->getOwnerRecord()->currency_code)
                    ->required()
                    ->live(),
                TextInput::make('exchange_rate')
                    ->label(__('forms.labels.exchange_rate'))
                    ->numeric()
                    ->step('0.00000001')
                    ->helperText(__('forms.helpers.rate_to_convert_to_document_currency_leave_empty_if_same'))
                    ->visible(fn ($get) => $get('currency_code') && $get('currency_code') !== $this->getOwnerRecord()->currency_code),
                Select::make('billable_to')
                    ->label(__('forms.labels.billable_to'))
                    ->options(function ($get) {
                        $options = collect(BillableTo::cases());

                        if ($this->isDiscountType($get)) {
                            // Desconto absorvido = simplesmente não lançar.
                            $options = $options->reject(fn (BillableTo $c) => $c === BillableTo::COMPANY);
                        }

                        return $options->mapWithKeys(fn (BillableTo $c) => [$c->value => $c->getLabel()])->all();
                    })
                    ->default(BillableTo::CLIENT->value)
                    ->required()
                    ->live(),
                Toggle::make('has_supplier_payable')
                    ->label(fn ($get) => $this->isDiscountType($get)
                        ? __('forms.labels.discount_supplier_side')
                        : __('forms.labels.supplier_payable'))
                    ->helperText(fn ($get) => $this->isDiscountType($get)
                        ? __('forms.helpers.discount_supplier_side')
                        : __('forms.helpers.supplier_payable'))
                    ->live()
                    ->default(false)
                    ->visible(fn ($get) => ! $this->isSupplierBillable($get) && ! $this->isCommissionType($get))
                    ->columnSpanFull(),
                Select::make('supplier_company_id')
                    ->label(function ($get) {
                        if ($this->isDiscountType($get) && ($this->isSupplierBillable($get) || $get('has_supplier_payable'))) {
                            return __('forms.labels.supplier');
                        }

                        return $get('has_supplier_payable') && ! $this->isSupplierBillable($get) && ! $this->isCommissionType($get)
                            ? __('forms.labels.supplier_to_pay')
                            : __('forms.labels.supplier_if_applicable');
                    })
                    ->relationship(
                        'supplierCompany',
                        'name',
                        // Fornecedores dos itens/POs deste documento primeiro,
                        // para minimizar lançamento no fornecedor errado.
                        modifyQueryUsing: function ($query) {
                            $ids = self::ownerSupplierIds($this->getOwnerRecord());

                            if ($ids->isEmpty()) {
                                return $query->orderBy('name');
                            }

                            return $query
                                ->orderByRaw('CASE WHEN companies.id IN ('.$ids->implode(',').') THEN 0 ELSE 1 END')
                                ->orderBy('name');
                        },
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(fn ($get) => ((bool) $get('has_supplier_payable') && ! $this->isSupplierBillable($get) && ! $this->isCommissionType($get))
                        || ($this->isDiscountType($get) && $this->isSupplierBillable($get)))
                    ->helperText(function ($get) {
                        // Aviso dinâmico: a qual documento o pagável vai ancorar.
                        if (! $get('has_supplier_payable') || $this->isSupplierBillable($get) || $this->isCommissionType($get)) {
                            return null;
                        }

                        $supplierId = $get('supplier_company_id');
                        if (! $supplierId || ! ($this->getOwnerRecord() instanceof ProformaInvoice)) {
                            return null;
                        }

                        $po = SyncSupplierPayableScheduleItemAction::resolveSupplierPo($this->getOwnerRecord(), (int) $supplierId);

                        return $po
                            ? __('forms.helpers.supplier_payable_will_link_po', ['po' => $po->reference])
                            : __('forms.helpers.supplier_payable_no_po');
                    })
                    ->placeholder('—'),
                TextInput::make('supplier_payable_amount')
                    ->label(fn ($get) => $this->isDiscountType($get)
                        ? __('forms.labels.discount_supplier_amount')
                        : __('forms.labels.supplier_payable_amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required(fn ($get) => (bool) $get('has_supplier_payable'))
                    ->visible(fn ($get) => (bool) $get('has_supplier_payable')
                        && ! $this->isSupplierBillable($get)
                        && ! $this->isCommissionType($get)),
                Select::make('supplier_payable_currency_code')
                    ->label(__('forms.labels.supplier_payable_currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->default(fn () => $this->getOwnerRecord()->currency_code)
                    ->live()
                    ->visible(fn ($get) => (bool) $get('has_supplier_payable')
                        && ! $this->isSupplierBillable($get)
                        && ! $this->isCommissionType($get)),
                TextInput::make('supplier_payable_exchange_rate')
                    ->label(__('forms.labels.exchange_rate'))
                    ->numeric()
                    ->step('0.00000001')
                    ->helperText(__('forms.helpers.rate_to_convert_to_document_currency_leave_empty_if_same'))
                    ->visible(fn ($get) => (bool) $get('has_supplier_payable')
                        && ! $this->isSupplierBillable($get)
                        && ! $this->isCommissionType($get)
                        && $get('supplier_payable_currency_code')
                        && $get('supplier_payable_currency_code') !== $this->getOwnerRecord()->currency_code),
                DatePicker::make('supplier_payable_due_date')
                    ->label(__('forms.labels.supplier_payable_due_date'))
                    ->visible(fn ($get) => (bool) $get('has_supplier_payable')
                        && ! $this->isSupplierBillable($get)
                        && ! $this->isCommissionType($get)),
                DatePicker::make('cost_date')
                    ->label(__('forms.labels.date'))
                    ->default(now()),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
                FileUpload::make('attachment_path')
                    ->label(__('forms.labels.attachment_invoicereceipt'))
                    ->disk('public')
                    ->directory('additional-cost-attachments')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
                Select::make('forwarder_company_id')
                    ->label(__('forms.labels.freight_forwarder'))
                    ->options(
                        fn () => Company::withRole(CompanyRole::FORWARDER)
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->placeholder('—')
                    ->default(fn () => $this->getOwnerRecord() instanceof Shipment
                        ? $this->getOwnerRecord()->forwarder_company_id
                        : null)
                    ->hidden(fn ($get) => ! $isFreight($get)),
                TextInput::make('forwarder_amount')
                    ->label(__('forms.labels.forwarder_amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->helperText(__('forms.helpers.amount_paid_to_forwarder'))
                    ->hidden(fn ($get) => ! $isFreight($get)),
                Select::make('forwarder_currency_code')
                    ->label(__('forms.labels.forwarder_currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->default(fn () => $this->getOwnerRecord()->currency_code)
                    ->live()
                    ->hidden(fn ($get) => ! $isFreight($get)),
                TextInput::make('forwarder_exchange_rate')
                    ->label(__('forms.labels.exchange_rate'))
                    ->numeric()
                    ->step('0.00000001')
                    ->helperText(__('forms.helpers.rate_to_convert_to_document_currency_leave_empty_if_same'))
                    ->hidden(fn ($get) => ! $isFreight($get) || ! $get('forwarder_currency_code') || $get('forwarder_currency_code') === $this->getOwnerRecord()->currency_code),
            ]),
        ];
    }

    protected function saveCost(array $data, $record = null): AdditionalCost
    {
        $owner = $this->getOwnerRecord();

        $isCommission = ($data['cost_type'] ?? null) === AdditionalCostType::COMMISSION->value
            || ($data['cost_type'] ?? null) === AdditionalCostType::COMMISSION
            || ($data['cost_type'] ?? null) === 'commission';

        $isDiscount = ($data['cost_type'] ?? null) === AdditionalCostType::DISCOUNT->value
            || ($data['cost_type'] ?? null) === AdditionalCostType::DISCOUNT
            || ($data['cost_type'] ?? null) === 'discount';

        $amountMinor = Money::toMinor((float) $data['amount']);

        $documentCurrencyCode = $owner->currency_code;
        $costCurrencyCode = $data['currency_code'];

        $hasSupplierPayable = (bool) ($data['has_supplier_payable'] ?? false)
            && ! $isCommission
            && ! $this->isSupplierBillableValue($data['billable_to'] ?? null)
            && ! empty($data['supplier_payable_amount'])
            && ! empty($data['supplier_company_id']);

        if ($record) {
            try {
                SyncSupplierPayableScheduleItemAction::assertSideRemovable(
                    $record,
                    $hasSupplierPayable,
                    isset($data['supplier_company_id']) ? (int) $data['supplier_company_id'] : null,
                    $data['supplier_payable_currency_code'] ?? null,
                );

                self::assertCostTypeSwitchable($record, $data['cost_type'] ?? $record->cost_type->value);
            } catch (ValidationException $e) {
                Notification::make()
                    ->title(collect($e->errors())->flatten()->first())
                    ->danger()
                    ->send();

                throw $e;
            }
        }

        $exchangeRate = null;
        $amountInDocCurrency = $amountMinor;

        if ($costCurrencyCode !== $documentCurrencyCode) {
            $exchangeRate = $data['exchange_rate'] ?? null;

            if ($exchangeRate) {
                $amountInDocCurrency = (int) round($amountMinor * (float) $exchangeRate);
            } else {
                $amountInDocCurrency = $this->convertCurrency($costCurrencyCode, $documentCurrencyCode, $amountMinor);
                if ($amountInDocCurrency !== $amountMinor) {
                    $exchangeRate = $amountInDocCurrency / max($amountMinor, 1);
                }
            }
        }

        $payload = [
            'cost_type' => $data['cost_type'],
            'commission_rate' => $isCommission ? ($data['commission_rate'] ?? null) : null,
            'commission_mode' => $isCommission ? ($data['commission_mode'] ?? null) : null,
            'description' => $data['description'],
            'amount' => $amountMinor,
            'currency_code' => $costCurrencyCode,
            'exchange_rate' => $exchangeRate,
            'amount_in_document_currency' => $amountInDocCurrency,
            'billable_to' => $data['billable_to'],
            'supplier_company_id' => $data['supplier_company_id'] ?? null,
            'cost_date' => $data['cost_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
        ];

        // Forwarder fields (only for FREIGHT type)
        $costTypeVal = $data['cost_type'] ?? null;
        $isFreight = $costTypeVal === AdditionalCostType::FREIGHT->value
            || $costTypeVal === AdditionalCostType::FREIGHT
            || $costTypeVal === 'freight';

        if ($isFreight && ! empty($data['forwarder_amount'])) {
            $forwarderAmountMinor = Money::toMinor((float) $data['forwarder_amount']);
            $forwarderCurrency = $data['forwarder_currency_code'] ?? $documentCurrencyCode;
            $forwarderExchangeRate = null;
            $forwarderAmountInDoc = $forwarderAmountMinor;

            if ($forwarderCurrency !== $documentCurrencyCode) {
                $forwarderExchangeRate = $data['forwarder_exchange_rate'] ?? null;

                if ($forwarderExchangeRate) {
                    $forwarderAmountInDoc = (int) round($forwarderAmountMinor * (float) $forwarderExchangeRate);
                } else {
                    $forwarderAmountInDoc = $this->convertCurrency($forwarderCurrency, $documentCurrencyCode, $forwarderAmountMinor);
                    if ($forwarderAmountInDoc !== $forwarderAmountMinor) {
                        $forwarderExchangeRate = $forwarderAmountInDoc / max($forwarderAmountMinor, 1);
                    }
                }
            }

            $payload['forwarder_company_id'] = $data['forwarder_company_id'] ?? null;
            $payload['forwarder_amount'] = $forwarderAmountMinor;
            $payload['forwarder_currency_code'] = $forwarderCurrency;
            $payload['forwarder_exchange_rate'] = $forwarderExchangeRate;
            $payload['forwarder_amount_in_document_currency'] = $forwarderAmountInDoc;
        } else {
            $payload['forwarder_company_id'] = null;
            $payload['forwarder_amount'] = null;
            $payload['forwarder_currency_code'] = null;
            $payload['forwarder_exchange_rate'] = null;
            $payload['forwarder_amount_in_document_currency'] = null;
        }

        // Supplier payable side — Impex pays the supplier for this cost.
        if ($hasSupplierPayable) {
            $spAmountMinor = Money::toMinor((float) $data['supplier_payable_amount']);
            $spCurrency = $data['supplier_payable_currency_code'] ?? $documentCurrencyCode;
            $spRate = null;
            $spInDoc = $spAmountMinor;

            if ($spCurrency !== $documentCurrencyCode) {
                $spRate = $data['supplier_payable_exchange_rate'] ?? null;

                if ($spRate) {
                    $spInDoc = (int) round($spAmountMinor * (float) $spRate);
                } else {
                    $spInDoc = $this->convertCurrency($spCurrency, $documentCurrencyCode, $spAmountMinor);
                    if ($spInDoc !== $spAmountMinor) {
                        $spRate = $spInDoc / max($spAmountMinor, 1);
                    }
                }
            }

            $payload += [
                'supplier_payable_amount' => $spAmountMinor,
                'supplier_payable_currency_code' => $spCurrency,
                'supplier_payable_exchange_rate' => $spRate,
                'supplier_payable_amount_in_document_currency' => $spInDoc,
                'supplier_payable_due_date' => $data['supplier_payable_due_date'] ?? null,
            ];
        } else {
            $payload += [
                'supplier_payable_amount' => null,
                'supplier_payable_currency_code' => null,
                'supplier_payable_exchange_rate' => null,
                'supplier_payable_amount_in_document_currency' => null,
                'supplier_payable_due_date' => null,
                'supplier_payable_status' => null,
            ];
        }

        try {
            if ($record) {
                $record->update($payload);

                return $record->fresh();
            }

            $payload['status'] = AdditionalCostStatus::PENDING->value;

            return $owner->additionalCosts()->create($payload);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw $e;
        }
    }

    protected function convertCurrency(string $fromCode, string $toCode, int $amountMinor): int
    {
        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();

        if ($fromCurrency && $toCurrency) {
            $converted = ExchangeRate::convert(
                $fromCurrency->id,
                $toCurrency->id,
                Money::toMajor($amountMinor)
            );

            if ($converted !== null) {
                return Money::toMinor($converted);
            }
        }

        return $amountMinor;
    }

    protected function syncScheduleItem(AdditionalCost $cost): void
    {
        $owner = $this->getOwnerRecord();
        $billableTo = $cost->billable_to instanceof BillableTo ? $cost->billable_to : BillableTo::from($cost->billable_to);

        if ($billableTo === BillableTo::COMPANY) {
            // Remove client schedule items (company absorbs the cost, not billed to client)
            PaymentScheduleItem::where('source_type', AdditionalCost::class)
                ->where('source_id', $cost->id)
                ->withoutSideTags()
                ->whereDoesntHave('allocations')
                ->delete();

            // Still create forwarder payable if forwarder data exists
            $this->syncForwarderScheduleItem($cost, $owner);

            app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $owner);

            return;
        }

        $payable = $this->resolvePayableForCost($cost, $owner, $billableTo);

        if (! $payable) {
            return;
        }

        $isCredit = $billableTo === BillableTo::SUPPLIER
            || $cost->cost_type === AdditionalCostType::DISCOUNT;

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getEnglishLabel()
            : $cost->cost_type;

        $label = $isCredit
            ? ($cost->cost_type === AdditionalCostType::DISCOUNT ? 'Discount: ' : 'Credit: ').$cost->description
            : "{$costTypeLabel}: {$cost->description}";

        // --- Client receivable schedule item (existing logic) ---
        $this->upsertScheduleItem($cost, $payable, [
            'label' => mb_substr($label, 0, 100),
            'amount' => abs($cost->amount_in_document_currency),
            'is_credit' => $isCredit,
            'notes' => $cost->notes,
        ], 'client');

        // --- Forwarder payable schedule item (new) ---
        $this->syncForwarderScheduleItem($cost, $owner);

        // --- Supplier payable schedule item ---
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $owner);
    }

    protected function upsertScheduleItem(AdditionalCost $cost, $payable, array $data, string $tag): void
    {
        $query = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id);

        if ($tag === 'forwarder') {
            $query->where('notes', 'LIKE', '%[forwarder-payable]%');
        } else {
            $query->withoutSideTags();
        }

        $existing = $query->first();

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->max('sort_order') ?? 0;

        $scheduleData = [
            'payable_type' => get_class($payable),
            'payable_id' => $payable->getKey(),
            'label' => $data['label'],
            'percentage' => 0,
            'amount' => $data['amount'],
            'currency_code' => $payable->currency_code ?? $cost->currency_code ?? 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => $data['is_credit'],
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => $maxSortOrder + 1,
            'notes' => $data['notes'],
        ];

        if ($existing) {
            $isPinned = $existing->isPinnedByAllocations();

            if ($isPinned) {
                $existing->update([
                    'label' => $scheduleData['label'],
                    'amount' => $scheduleData['amount'],
                    'is_credit' => $scheduleData['is_credit'],
                    'notes' => $scheduleData['notes'],
                ]);
            } else {
                $existing->update($scheduleData);
            }
        } else {
            PaymentScheduleItem::create($scheduleData);
        }
    }

    protected function syncForwarderScheduleItem(AdditionalCost $cost, $owner): void
    {
        $forwarderTag = '[forwarder-payable]';

        $existing = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->where('notes', 'LIKE', "%{$forwarderTag}%")
            ->first();

        // If no forwarder amount, remove any existing forwarder schedule item
        if (! $cost->forwarder_amount || ! $cost->forwarder_company_id) {
            if ($existing && ! $existing->allocations()->exists()) {
                $existing->delete();
            }

            return;
        }

        $payable = $owner instanceof Shipment ? $owner : $owner;

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getEnglishLabel()
            : $cost->cost_type;

        $forwarderName = $cost->forwarderCompany?->name ?? 'Forwarder';
        $label = mb_substr("{$costTypeLabel} payable: {$forwarderName} - {$cost->description}", 0, 100);

        $maxSortOrder = PaymentScheduleItem::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->max('sort_order') ?? 0;

        $scheduleData = [
            'payable_type' => get_class($payable),
            'payable_id' => $payable->getKey(),
            'label' => $label,
            'percentage' => 0,
            'amount' => $cost->forwarder_amount,
            'currency_code' => $cost->forwarder_currency_code ?? $payable->currency_code ?? 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => $maxSortOrder + 1,
            'notes' => "{$forwarderTag} {$cost->notes}",
        ];

        if ($existing) {
            if ($existing->allocations()->exists()) {
                $existing->update([
                    'label' => $scheduleData['label'],
                    'amount' => $scheduleData['amount'],
                    'currency_code' => $scheduleData['currency_code'],
                    'notes' => $scheduleData['notes'],
                ]);
            } else {
                $existing->update($scheduleData);
            }
        } else {
            PaymentScheduleItem::create($scheduleData);
        }
    }

    protected function resolvePayableForCost(AdditionalCost $cost, $owner, BillableTo $billableTo)
    {
        if ($owner instanceof Shipment) {
            return $owner;
        }

        if ($billableTo === BillableTo::CLIENT) {
            if ($owner instanceof ProformaInvoice) {
                return $owner;
            }
            if ($owner instanceof PurchaseOrder) {
                return $owner->proformaInvoice;
            }
        }

        if ($billableTo === BillableTo::SUPPLIER) {
            if ($owner instanceof PurchaseOrder) {
                return $owner;
            }
            if ($owner instanceof ProformaInvoice) {
                $po = $owner->purchaseOrders()->first();
                if ($po) {
                    return $po;
                }

                return $owner;
            }
        }

        return $owner;
    }

    protected function isEmbeddedCommission($cost): bool
    {
        if (! $cost) {
            return false;
        }

        $costType = $cost->cost_type;
        $isCommission = $costType === AdditionalCostType::COMMISSION
            || (is_string($costType) && $costType === 'commission');

        $mode = $cost->commission_mode;
        $isEmbedded = $mode === CommissionType::EMBEDDED
            || (is_string($mode) && $mode === 'embedded');

        return $isCommission && $isEmbedded;
    }

    protected function applyEmbeddedCommission(AdditionalCost $cost): void
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof ProformaInvoice) {
            return;
        }

        $rate = (float) $cost->commission_rate;
        if ($rate <= 0) {
            return;
        }

        $multiplier = 1 + ($rate / 100);

        $owner->items()->get()->each(function (ProformaInvoiceItem $item) use ($multiplier) {
            $newUnitPrice = (int) round($item->unit_price * $multiplier);
            $item->update(['unit_price' => $newUnitPrice]);
        });
    }

    protected function revertEmbeddedCommission(AdditionalCost $cost): void
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof ProformaInvoice) {
            return;
        }

        $rate = (float) $cost->commission_rate;
        if ($rate <= 0) {
            return;
        }

        $multiplier = 1 + ($rate / 100);

        $owner->items()->get()->each(function (ProformaInvoiceItem $item) use ($multiplier) {
            $originalUnitPrice = (int) round($item->unit_price / $multiplier);
            $item->update(['unit_price' => $originalUnitPrice]);
        });
    }

    protected function costStatementAction(): Action
    {
        return Action::make('costStatement')
            ->label(__('forms.labels.cost_statement'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->visible(fn () => $this->getOwnerRecord() instanceof ProformaInvoice
                && $this->getOwnerRecord()
                    ->additionalCosts()
                    ->where('billable_to', BillableTo::CLIENT)
                    ->exists())
            ->action(function () {
                try {
                    $template = new CostStatementPdfTemplate($this->getOwnerRecord());
                    $service = new PdfGeneratorService(
                        new PdfRenderer,
                        new DocumentService,
                    );

                    $content = $service->preview($template);

                    return response()->streamDownload(
                        function () use ($content) {
                            echo $content;
                        },
                        $template->getFilename(),
                        [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="'.$template->getFilename().'"',
                        ],
                    );
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Cost Statement Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
