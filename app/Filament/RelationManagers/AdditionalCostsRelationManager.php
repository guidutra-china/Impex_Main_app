<?php

namespace App\Filament\RelationManagers;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
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
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AdditionalCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'additionalCosts';

    protected static ?string $title = 'Additional Costs';

    protected static BackedEnum|string|null $icon = 'heroicon-o-receipt-percent';

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
                    ->formatStateUsing(fn ($state) => $state ? $state . '%' : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_mode')
                    ->label(__('forms.labels.commission_model'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('amount_in_document_currency')
                    ->label(__('forms.labels.doc_amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label(__('forms.labels.total'))
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('billable_to')
                    ->label(__('forms.labels.billable_to'))
                    ->badge(),
                TextColumn::make('forwarderCompany.name')
                    ->label(__('forms.labels.freight_forwarder'))
                    ->placeholder('—')
                    ->visible(fn () => $this->getOwnerRecord() instanceof Shipment),
                TextColumn::make('forwarder_amount_in_document_currency')
                    ->label(__('forms.labels.forwarder_amount'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->alignEnd()
                    ->visible(fn () => $this->getOwnerRecord() instanceof Shipment),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_date')
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
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
            ->visible(fn ($record) => $record->status === AdditionalCostStatus::PENDING && auth()->user()?->can('create-payments'))
            ->fillForm(fn ($record) => [
                'cost_type' => $record->cost_type->value,
                'commission_rate' => $record->commission_rate,
                'commission_mode' => $record->commission_mode?->value,
                'description' => $record->description,
                'amount' => Money::toMajor($record->amount),
                'currency_code' => $record->currency_code,
                'exchange_rate' => $record->exchange_rate,
                'billable_to' => $record->billable_to->value,
                'supplier_company_id' => $record->supplier_company_id,
                'forwarder_company_id' => $record->forwarder_company_id,
                'forwarder_amount' => $record->forwarder_amount ? Money::toMajor($record->forwarder_amount) : null,
                'forwarder_currency_code' => $record->forwarder_currency_code,
                'forwarder_exchange_rate' => $record->forwarder_exchange_rate,
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
            ->visible(fn ($record) => in_array($record->status, [AdditionalCostStatus::PENDING, AdditionalCostStatus::INVOICED]) && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                // Revert embedded commission before waiving
                if ($this->isEmbeddedCommission($record)) {
                    $this->revertEmbeddedCommission($record);
                }

                $record->update(['status' => AdditionalCostStatus::WAIVED]);

                PaymentScheduleItem::where('source_type', AdditionalCost::class)
                    ->where('source_id', $record->id)
                    ->get()
                    ->each(function ($scheduleItem) {
                        $scheduleItem->update([
                            'status' => PaymentScheduleStatus::WAIVED,
                            'waived_by' => auth()->id(),
                            'waived_at' => now(),
                        ]);
                    });

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
            ->visible(fn ($record) => $record->status === AdditionalCostStatus::PENDING && auth()->user()?->can('create-payments'))
            ->action(function ($record) {
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
                    ->live(),
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
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, $get, $set) {
                        $owner = $this->getOwnerRecord();
                        if ($owner instanceof ProformaInvoice && $state > 0) {
                            $subtotal = $owner->subtotal;
                            $calculatedAmount = Money::toMajor((int) round($subtotal * ((float) $state / 100)));
                            $set('amount', number_format($calculatedAmount, 2, '.', ''));
                        }
                    }),
                TextInput::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->helperText(fn ($get) => $isFreight($get) ? __('forms.helpers.amount_charged_to_client') : ($isCommission($get) ? __('forms.helpers.auto_calculated_from_commission_rate') : null))
                    ->readOnly(fn ($get) => $isCommission($get) && $get('commission_rate') > 0),
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
                    ->options(BillableTo::class)
                    ->default(BillableTo::CLIENT->value)
                    ->required(),
                Select::make('supplier_company_id')
                    ->label(__('forms.labels.supplier_if_applicable'))
                    ->relationship('supplierCompany', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('—'),
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

        // For commission with rate: recalculate amount from percentage
        $isCommission = ($data['cost_type'] ?? null) === AdditionalCostType::COMMISSION->value
            || ($data['cost_type'] ?? null) === AdditionalCostType::COMMISSION
            || ($data['cost_type'] ?? null) === 'commission';

        if ($isCommission && ! empty($data['commission_rate']) && $owner instanceof ProformaInvoice) {
            $subtotal = $owner->subtotal;
            $calculatedAmount = (int) round($subtotal * ((float) $data['commission_rate'] / 100));
            $amountMinor = $calculatedAmount;
        } else {
            $amountMinor = Money::toMinor((float) $data['amount']);
        }

        $documentCurrencyCode = $owner->currency_code;
        $costCurrencyCode = $data['currency_code'];

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

        if ($record) {
            $record->update($payload);
            return $record->fresh();
        }

        $payload['status'] = AdditionalCostStatus::PENDING->value;
        return $owner->additionalCosts()->create($payload);
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
            PaymentScheduleItem::where('source_type', AdditionalCost::class)
                ->where('source_id', $cost->id)
                ->whereDoesntHave('allocations')
                ->delete();
            return;
        }

        $payable = $this->resolvePayableForCost($cost, $owner, $billableTo);

        if (! $payable) {
            return;
        }

        $isCredit = $billableTo === BillableTo::SUPPLIER;

        $costTypeLabel = $cost->cost_type instanceof AdditionalCostType
            ? $cost->cost_type->getLabel()
            : $cost->cost_type;

        $label = $isCredit
            ? "Credit: {$cost->description}"
            : "{$costTypeLabel}: {$cost->description}";

        // --- Client receivable schedule item (existing logic) ---
        $this->upsertScheduleItem($cost, $payable, [
            'label' => mb_substr($label, 0, 100),
            'amount' => $cost->amount_in_document_currency,
            'is_credit' => $isCredit,
            'notes' => $cost->notes,
        ], 'client');

        // --- Forwarder payable schedule item (new) ---
        $this->syncForwarderScheduleItem($cost, $owner);
    }

    protected function upsertScheduleItem(AdditionalCost $cost, $payable, array $data, string $tag): void
    {
        $query = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id);

        if ($tag === 'forwarder') {
            $query->where('notes', 'LIKE', '%[forwarder-payable]%');
        } else {
            $query->where(function ($q) {
                $q->whereNull('notes')
                    ->orWhere('notes', 'NOT LIKE', '%[forwarder-payable]%');
            });
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
            if ($existing->allocations()->exists()) {
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
            ? $cost->cost_type->getLabel()
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
            'amount' => $cost->forwarder_amount_in_document_currency,
            'currency_code' => $payable->currency_code ?? $cost->forwarder_currency_code ?? 'USD',
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
                        ->title('Cost Statement Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
