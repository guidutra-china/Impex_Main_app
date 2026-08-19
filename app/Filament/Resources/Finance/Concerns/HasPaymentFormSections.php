<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\SyncPaymentBankFeeAction;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AllocationCalculator;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentMethod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

trait HasPaymentFormSections
{
    public static function formSchema(PaymentDirection $direction): array
    {
        $companyLabel = $direction === PaymentDirection::INBOUND
            ? __('forms.labels.client')
            : __('forms.labels.supplier');

        return [
            Section::make(__('forms.sections.payment_information'))->columns(2)->columnSpanFull()->schema([
                Hidden::make('direction')->default($direction->value),
                Select::make('company_id')
                    ->label($companyLabel)
                    ->options(function () use ($direction) {
                        $query = Company::query();
                        if ($direction === PaymentDirection::INBOUND) {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', 'client'));
                        } else {
                            $query->whereHas('companyRoles', fn ($q) => $q->whereIn('role', ['supplier', 'forwarder']));
                        }

                        return $query->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('allocations', []);
                        $set('credit_applications', []);
                        $set('amount', null);
                    }),
                Select::make('currency_code')
                    ->label(__('forms.labels.payment_currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        // Bank fees are almost always charged in the currency
                        // of the wire; pre-fill it while the user hasn't
                        // chosen otherwise.
                        if (blank($get('bank_fee_currency_code'))) {
                            $set('bank_fee_currency_code', $state);
                        }
                    }),
                TextInput::make('amount')
                    ->label(__('forms.labels.wire_transfer_amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(__('forms.helpers.actual_amount_transferred_credits_are_applied_separately')),
                DatePicker::make('payment_date')
                    ->label(__('forms.labels.payment_date'))
                    ->default(now())
                    ->required(),
                Select::make('payment_method_id')
                    ->label(__('forms.labels.payment_method'))
                    ->options(fn () => PaymentMethod::active()->pluck('name', 'id')),
                Select::make('bank_account_id')
                    ->label(__('forms.labels.bank_account'))
                    ->options(fn () => BankAccount::active()->get()->mapWithKeys(fn ($ba) => [
                        $ba->id => $ba->bank_name.' — '.$ba->account_name.' ('.$ba->currency?->code.')',
                    ])),
                TextInput::make('reference')
                    ->label(__('forms.labels.reference_swift_transfer'))
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
                FileUpload::make('attachment_path')
                    ->label(__('forms.labels.attachment_receiptswift'))
                    ->directory('payment-attachments')
                    ->disk('public')
                    ->visibility('public')
                    ->openable()
                    ->downloadable()
                    ->previewable()
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
            ]),

            Section::make(__('forms.sections.bank_fee'))
                ->columns(2)
                ->collapsible()
                ->collapsed(fn (Get $get) => blank($get('bank_fee_amount')))
                ->visible($direction === PaymentDirection::OUTBOUND)
                ->schema([
                    TextInput::make('bank_fee_amount')
                        ->label(__('forms.labels.bank_fee_amount'))
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0.01)
                        ->live(onBlur: true)
                        ->helperText(__('forms.helpers.bank_fee_amount')),
                    Select::make('bank_fee_currency_code')
                        ->label(__('forms.labels.bank_fee_currency'))
                        ->options(fn () => Currency::pluck('code', 'code'))
                        ->default(fn (Get $get) => $get('currency_code'))
                        ->required(fn (Get $get) => filled($get('bank_fee_amount'))),
                    Select::make('bank_fee_billable_to')
                        ->label(__('forms.labels.bank_fee_billable_to'))
                        ->options(fn () => collect(BillableTo::cases())
                            ->mapWithKeys(fn (BillableTo $case) => [$case->value => $case->getLabel()])
                            ->all())
                        ->default(BillableTo::CLIENT->value)
                        ->helperText(__('forms.helpers.bank_fee_billable_to'))
                        ->required(fn (Get $get) => filled($get('bank_fee_amount'))),
                    Select::make('bank_fee_process')
                        ->label(__('forms.labels.bank_fee_process'))
                        ->options(fn (Get $get) => static::bankFeeProcessOptions($get('allocations') ?? []))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => static::searchBankFeeProcesses($search))
                        ->getOptionLabelUsing(fn ($value) => static::bankFeeProcessLabel($value))
                        ->helperText(__('forms.helpers.bank_fee_process'))
                        ->required(fn (Get $get) => filled($get('bank_fee_amount')))
                        ->columnSpanFull(),
                    TextInput::make('bank_fee_description')
                        ->label(__('forms.labels.bank_fee_description'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make(__('forms.sections.outstanding_items_credits'))
                ->description(__('forms.descriptions.overview_of_pending_schedule_items_and_available_credits'))
                ->visible(fn (Get $get) => filled($get('company_id')))
                ->collapsible()
                ->schema([
                    Placeholder::make('combined_overview')
                        ->label('')
                        ->content(function (Get $get) use ($direction) {
                            $companyId = (int) $get('company_id');

                            if (! $companyId) {
                                return 'Select a company to see outstanding items.';
                            }

                            $items = static::getCompanyScheduleItems($companyId, $direction);
                            $credits = static::getCompanyCreditItems($companyId, $direction);

                            if ($items->isEmpty() && $credits->isEmpty()) {
                                return new HtmlString('<p class="text-sm text-gray-500">No pending schedule items or credits for this company.</p>');
                            }

                            $html = '';

                            if ($items->isNotEmpty()) {
                                $html .= static::buildOutstandingTable($items);
                            }

                            if ($credits->isNotEmpty()) {
                                if ($items->isNotEmpty()) {
                                    $html .= '<div class="my-3 border-t border-gray-200 dark:border-gray-700"></div>';
                                }
                                $html .= static::buildCreditsTable($credits);
                            }

                            return new HtmlString($html);
                        }),
                ])
                ->columnSpanFull(),

            Section::make(__('forms.sections.allocations'))
                ->description(__('forms.descriptions.allocate_the_wire_transfer_amount_to_schedule_items'))
                ->visible(fn (Get $get) => filled($get('company_id')))
                ->schema([
                    Repeater::make('allocations')
                        ->label('')
                        ->schema(static::allocationRepeaterSchema($direction))
                        ->columns(12)
                        ->defaultItems(0)
                        ->addActionLabel('+ Add Allocation')
                        ->deleteAction(fn ($action) => $action->after(function (Get $get, Set $set) {
                            static::recalculateTotal($get, $set);
                        }))
                        ->live()
                        // Guard anti-dupla-contagem: dinheiro alocado não pode
                        // exceder o valor do pagamento, e dinheiro + crédito da
                        // MESMA parcela não pode exceder o saldo dela (o prefill
                        // preenche o valor cheio; o crédito completa o resto).
                        ->rule(static fn (Get $get, ?\Illuminate\Database\Eloquent\Model $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record) {
                            $errors = \App\Domain\Financial\Support\AllocationGuards::overpayErrors(
                                is_array($value) ? array_values($value) : [],
                                array_values($get('credit_applications') ?? []),
                                filled($get('amount')) ? (float) $get('amount') : null,
                                $record?->getKey(),
                            );

                            foreach ($errors as $error) {
                                $fail($error);
                            }
                        })
                        ->columnSpanFull(),

                    Placeholder::make('allocation_summary')
                        ->label('')
                        ->content(function (Get $get) {
                            return new HtmlString(static::buildAllocationSummary(
                                $get('allocations') ?? [],
                                (float) ($get('amount') ?? 0),
                                (string) ($get('currency_code') ?? ''),
                            ));
                        }),
                ])
                ->columnSpanFull(),

            Section::make(__('forms.sections.credit_applications'))
                ->description(__('forms.descriptions.apply_credits_to_offset_schedule_item_balances_this_does'))
                ->visible(fn (Get $get) => filled($get('company_id'))
                    && static::getCompanyCreditItems((int) $get('company_id'), $direction)->isNotEmpty())
                ->schema([
                    static::creditApplicationsRepeater($direction),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * Repeater for applying available credits (Credit Notes, supplier
     * deductions) against open schedule items. Shared between the
     * Create/Edit payment form and the "Manage Allocations" modal so an
     * approved payment can still consume a credit. Reads ../../company_id,
     * so company_id must exist at the enclosing form root.
     */
    public static function creditApplicationsRepeater(PaymentDirection $direction): Repeater
    {
        return Repeater::make('credit_applications')
            ->hiddenLabel()
            ->schema([
                Select::make('credit_schedule_item_id')
                    ->label(__('forms.labels.credit'))
                    ->options(function (Get $get, ?\Illuminate\Database\Eloquent\Model $record) use ($direction) {
                        $companyId = $get('../../company_id');
                        if (! $companyId) {
                            return [];
                        }

                        $items = static::getCompanyCreditItems((int) $companyId, $direction);

                        // No Edit, um crédito já consumido POR ESTE pagamento
                        // está PAID e sumiria das opções — a linha rehidratada
                        // falharia na validação. Reinclui os créditos do
                        // próprio pagamento.
                        if ($record) {
                            $own = PaymentScheduleItem::query()
                                ->where('is_credit', true)
                                ->whereHas('creditAllocations', fn ($q) => $q->where('payment_id', $record->getKey()))
                                ->with('payable')
                                ->get();

                            $items = $items->concat($own)->unique('id')->values();
                        }

                        return $items->mapWithKeys(fn ($item) => [
                            $item->id => static::formatCreditItemLabel($item),
                        ]);
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        $item = PaymentScheduleItem::with('payable')->find($value);

                        return $item ? static::formatCreditItemLabel($item) : null;
                    })
                    ->required()
                    ->distinct()
                    ->searchable()
                    ->columnSpan(4),
                Select::make('payment_schedule_item_id')
                    ->label(__('forms.labels.apply_to'))
                    ->options(function (Get $get) use ($direction) {
                        $companyId = $get('../../company_id');
                        if (! $companyId) {
                            return [];
                        }

                        return static::getCompanyScheduleItems((int) $companyId, $direction)
                            ->mapWithKeys(fn ($item) => [
                                $item->id => static::formatScheduleItemLabel($item),
                            ]);
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        $item = PaymentScheduleItem::with('payable')->find($value);

                        return $item ? static::formatScheduleItemLabel($item) : null;
                    })
                    ->required()
                    ->searchable()
                    ->columnSpan(4),
                TextInput::make('credit_amount')
                    ->label(__('forms.labels.amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->rules([
                        fn (Get $get, ?\Illuminate\Database\Eloquent\Model $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $creditItemId = $get('credit_schedule_item_id');
                            if (! $creditItemId) {
                                return;
                            }
                            $creditItem = PaymentScheduleItem::find($creditItemId);
                            if (! $creditItem) {
                                return;
                            }
                            $maxAmount = static::maxApplicableCreditMajor($creditItem, $record?->getKey());
                            if ((float) $value > $maxAmount) {
                                $fail("Amount cannot exceed the available credit of {$creditItem->currency_code} ".number_format($maxAmount, 2).'.');
                            }
                        },
                    ])
                    ->columnSpan(2),
            ])
            ->columns(10)
            ->defaultItems(0)
            ->addActionLabel('+ Apply Credit')
            ->live()
            ->columnSpanFull();
    }

    /**
     * Processes (PI / Shipment) implied by the allocations already filled in
     * the form — the fee almost always belongs to one of them.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<string, string>
     */
    public static function bankFeeProcessOptions(array $allocations): array
    {
        $itemIds = collect($allocations)
            ->pluck('payment_schedule_item_id')
            ->filter()
            ->unique();

        if ($itemIds->isEmpty()) {
            return [];
        }

        $options = [];

        foreach (PaymentScheduleItem::with('payable')->whereIn('id', $itemIds)->get() as $item) {
            $process = static::bankFeeProcessFor($item->payable);

            if ($process) {
                $options[static::bankFeeProcessKey($process)] = static::bankFeeProcessOptionLabel($process);
            }
        }

        return $options;
    }

    /**
     * Free search over every process, because the fee is often charged to a
     * client while the payment itself went to a supplier.
     *
     * @return array<string, string>
     */
    public static function searchBankFeeProcesses(string $search): array
    {
        $options = [];

        $matches = fn ($query) => $query
            ->where(fn ($q) => $q->where('reference', 'LIKE', "%{$search}%")
                ->orWhereHas('company', fn ($c) => $c->where('name', 'LIKE', "%{$search}%")))
            ->with('company')
            ->latest('id')
            ->limit(15)
            ->get();

        foreach ($matches(ProformaInvoice::query())->concat($matches(Shipment::query())) as $process) {
            $options[static::bankFeeProcessKey($process)] = static::bankFeeProcessOptionLabel($process);
        }

        return $options;
    }

    public static function bankFeeProcessLabel(?string $value): ?string
    {
        $process = app(SyncPaymentBankFeeAction::class)->resolveProcess($value);

        return $process ? static::bankFeeProcessOptionLabel($process) : null;
    }

    public static function bankFeeProcessKey(\Illuminate\Database\Eloquent\Model $process): string
    {
        return get_class($process).':'.$process->getKey();
    }

    protected static function bankFeeProcessOptionLabel(\Illuminate\Database\Eloquent\Model $process): string
    {
        $company = $process->company?->name;

        return trim(($process->reference ?? '#'.$process->getKey()).($company ? " — {$company}" : ''));
    }

    /**
     * The process a schedule item belongs to: PO installments roll up to
     * their PI, PI and shipment items are already the process.
     */
    protected static function bankFeeProcessFor(mixed $payable): ?\Illuminate\Database\Eloquent\Model
    {
        return match (true) {
            $payable instanceof ProformaInvoice, $payable instanceof Shipment => $payable,
            $payable instanceof PurchaseOrder => $payable->proformaInvoice,
            default => null,
        };
    }

    public static function getCompanyScheduleItems(int $companyId, mixed $direction): Collection
    {
        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

        $isForwarder = Company::find($companyId)?->isForwarder() ?? false;

        $query = PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
            // Receivables never include payable-side rows (forwarder/supplier).
            $query->withoutSideTags();
            // Client-side allocations (AR) target two distinct kinds of items:
            //
            //   (a) PI installments — payable_type=PI, payable_id ∈ piIds.
            //       Always selected here (canonical PI installment row).
            //
            //   (b) Client-billable additional costs (e.g. freight that the
            //       client repasses) — these are PaymentScheduleItem rows with
            //       source_type=AdditionalCost and source_id pointing to a cost
            //       whose billable_to=CLIENT and whose costable is a Shipment
            //       or PI belonging to this client. Their payable_type is
            //       Shipment (not PI), so the previous PI-only filter hid them.
            //
            // PI→Shipment mirrors stay excluded because they have payable_type=
            // Shipment but source_type ≠ AdditionalCost (mirror sync is one-way
            // PI → Shipment, never Shipment → PI; allocating against the mirror
            // would leave the PI installment stuck PENDING). Forwarder- and
            // supplier-payable costs are also excluded by the withoutSideTags()
            // scope applied earlier in the query.
            $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');
            $shipmentIds = Shipment::where('company_id', $companyId)->pluck('id');

            $clientCostIds = AdditionalCost::query()
                ->where('billable_to', BillableTo::CLIENT)
                ->where(function ($q) use ($piIds, $shipmentIds) {
                    $q->where(function ($q2) use ($shipmentIds) {
                        $q2->where('costable_type', Shipment::class)
                            ->whereIn('costable_id', $shipmentIds);
                    })->orWhere(function ($q2) use ($piIds) {
                        $q2->where('costable_type', ProformaInvoice::class)
                            ->whereIn('costable_id', $piIds);
                    });
                })
                ->pluck('id');

            // (c) Issued CLIENT Debit Notes — payable_type=DebitNote, payable_id ∈ dnIds.
            //     Schedule items are created by IssueDebitNoteAction with the DN
            //     itself as the payable so DNs without a PI are still allocatable.
            //     Supplier-party DNs are payables and surface in the OUTBOUND branch.
            $dnIds = DebitNote::where('company_id', $companyId)
                ->where('party_type', PartyType::CLIENT->value)
                ->where('status', DebitNoteStatus::ISSUED->value)
                ->pluck('id');

            $query->where(function ($q) use ($piIds, $clientCostIds, $dnIds) {
                $q->where(function ($q2) use ($piIds) {
                    $q2->where('payable_type', ProformaInvoice::class)
                        ->whereIn('payable_id', $piIds);
                });
                if ($clientCostIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($clientCostIds) {
                        $q2->where('source_type', AdditionalCost::class)
                            ->whereIn('source_id', $clientCostIds);
                    });
                }
                if ($dnIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($dnIds) {
                        $q2->where('payable_type', DebitNote::class)
                            ->whereIn('payable_id', $dnIds);
                    });
                }
            });
        } else {
            if ($isForwarder) {
                $costableIds = \App\Domain\Financial\Models\AdditionalCost::where('forwarder_company_id', $companyId)
                    ->where('costable_type', (new Shipment)->getMorphClass())
                    ->pluck('costable_id')
                    ->unique();

                $forwarderCostIds = \App\Domain\Financial\Models\AdditionalCost::where('forwarder_company_id', $companyId)
                    ->pluck('id');

                $query->where(function ($q) use ($costableIds) {
                    $q->where(function ($q2) use ($costableIds) {
                        $q2->where('payable_type', Shipment::class)->whereIn('payable_id', $costableIds);
                    });
                    $q->where('notes', 'LIKE', '%[forwarder-payable]%');
                });
            } else {
                // Forwarder-payable rows belong to the forwarder branch above.
                $query->where(function ($q) {
                    $q->whereNull('notes')
                        ->orWhere('notes', 'NOT LIKE', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%');
                });

                $poIds = PurchaseOrder::where('supplier_company_id', $companyId)->pluck('id');

                // Shipment-level items only surface if they are additional costs
                // whose supplier_company_id matches the selected supplier.
                // Without this filter, freight/commission items belonging to
                // other parties on the same shipment would leak into this
                // supplier's outstanding list.
                $supplierAdditionalCostIds = \App\Domain\Financial\Models\AdditionalCost::query()
                    ->where('supplier_company_id', $companyId)
                    ->pluck('id');

                // Issued SUPPLIER Debit Notes — extra amounts Impex owes the
                // supplier (payables) owned by the DN itself, mirroring how the
                // INBOUND branch surfaces client DNs as receivables.
                $supplierDnIds = DebitNote::where('company_id', $companyId)
                    ->where('party_type', PartyType::SUPPLIER->value)
                    ->where('status', DebitNoteStatus::ISSUED->value)
                    ->pluck('id');

                $query->where(function ($q) use ($poIds, $supplierAdditionalCostIds, $supplierDnIds) {
                    $q->where(function ($q2) use ($poIds) {
                        $q2->where('payable_type', PurchaseOrder::class)->whereIn('payable_id', $poIds);
                    });
                    if ($supplierAdditionalCostIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($supplierAdditionalCostIds) {
                            $q2->where('source_type', \App\Domain\Financial\Models\AdditionalCost::class)
                                ->whereIn('source_id', $supplierAdditionalCostIds)
                                ->where('notes', 'LIKE', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%');
                        });
                    }
                    if ($supplierDnIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($supplierDnIds) {
                            $q2->where('payable_type', DebitNote::class)
                                ->whereIn('payable_id', $supplierDnIds);
                        });
                    }
                });
            }
        }

        return $query->with(['payable', 'shipment'])->get();
    }

    public static function getCompanyCreditItems(int $companyId, mixed $direction): Collection
    {
        $directionValue = $direction instanceof PaymentDirection ? $direction->value : $direction;

        $isForwarder = Company::find($companyId)?->isForwarder() ?? false;

        $query = PaymentScheduleItem::query()
            ->where('is_credit', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ]);

        // Credit notes issued to this company become credit items owned by
        // the CreditNote itself; surface them alongside document credits.
        $creditNoteIds = \App\Domain\Financial\Models\CreditNote::query()
            ->where('company_id', $companyId)
            ->where('party_type', ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound')
                ? PartyType::CLIENT->value
                : PartyType::SUPPLIER->value)
            ->pluck('id');

        if ($directionValue === PaymentDirection::INBOUND->value || $directionValue === 'inbound') {
            // Same rule as getCompanyScheduleItems: client AR credits attach
            // to the PI canonical item, never to a shipment mirror.
            // Supplier-leg credits (tag [supplier-payable], e.g. the supplier
            // side of a discount) belong to the OUTBOUND direction even when
            // they fall back to PI anchoring while the PO doesn't exist yet.
            $query->withoutSideTags();

            $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');

            $query->where(function ($q) use ($piIds, $creditNoteIds) {
                $q->where(function ($q2) use ($piIds) {
                    $q2->where('payable_type', ProformaInvoice::class)
                        ->whereIn('payable_id', $piIds);
                });
                if ($creditNoteIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($creditNoteIds) {
                        $q2->where('payable_type', \App\Domain\Financial\Models\CreditNote::class)
                            ->whereIn('payable_id', $creditNoteIds);
                    });
                }
            });

            // A supplier-billable discount/credit can fall back to anchoring
            // on the PI when it has no PO yet (see
            // SyncSupplierPayableScheduleItemAction). The predicate above
            // matches any PI-anchored credit regardless of whose credit it
            // is, so exclude credits sourced from a non-CLIENT additional
            // cost — they belong to the OUTBOUND (supplier) side only.
            $nonClientCostIds = AdditionalCost::query()
                ->where('billable_to', '!=', BillableTo::CLIENT->value)
                ->pluck('id');

            if ($nonClientCostIds->isNotEmpty()) {
                $query->where(function ($q) use ($nonClientCostIds) {
                    $q->where('source_type', '!=', AdditionalCost::class)
                        ->orWhereNull('source_type')
                        ->orWhereNotIn('source_id', $nonClientCostIds);
                });
            }
        } else {
            if ($isForwarder) {
                $costableIds = \App\Domain\Financial\Models\AdditionalCost::where('forwarder_company_id', $companyId)
                    ->where('costable_type', (new Shipment)->getMorphClass())
                    ->pluck('costable_id')
                    ->unique();

                $query->where(function ($q) use ($costableIds, $creditNoteIds) {
                    $q->where(function ($q2) use ($costableIds) {
                        $q2->where('payable_type', Shipment::class)->whereIn('payable_id', $costableIds);
                    });
                    if ($creditNoteIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($creditNoteIds) {
                            $q2->where('payable_type', \App\Domain\Financial\Models\CreditNote::class)
                                ->whereIn('payable_id', $creditNoteIds);
                        });
                    }
                });
            } else {
                $poIds = PurchaseOrder::where('supplier_company_id', $companyId)->pluck('id');

                // Shipment-level items only surface if they are additional costs
                // whose supplier_company_id matches the selected supplier.
                // Without this filter, freight/commission items belonging to
                // other parties on the same shipment would leak into this
                // supplier's outstanding list.
                $supplierAdditionalCostIds = \App\Domain\Financial\Models\AdditionalCost::query()
                    ->where('supplier_company_id', $companyId)
                    ->pluck('id');

                // Untagged cost credits only belong to the supplier when the
                // cost is billed TO the supplier; a CLIENT-billable cost with
                // the informational supplier field filled must not leak its
                // client-leg credit into this supplier's list. Tagged rows
                // ([supplier-payable]) are the supplier leg by construction.
                $supplierBilledCostIds = \App\Domain\Financial\Models\AdditionalCost::query()
                    ->where('supplier_company_id', $companyId)
                    ->where('billable_to', BillableTo::SUPPLIER->value)
                    ->pluck('id');

                $query->where(function ($q) use ($poIds, $supplierAdditionalCostIds, $supplierBilledCostIds, $creditNoteIds) {
                    $q->where(function ($q2) use ($poIds) {
                        $q2->where('payable_type', PurchaseOrder::class)->whereIn('payable_id', $poIds)->withoutSideTags();
                    });
                    if ($supplierAdditionalCostIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($supplierAdditionalCostIds) {
                            $q2->where('source_type', \App\Domain\Financial\Models\AdditionalCost::class)
                                ->whereIn('source_id', $supplierAdditionalCostIds)
                                ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG);
                        });
                    }
                    if ($supplierBilledCostIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($supplierBilledCostIds) {
                            $q2->where('source_type', \App\Domain\Financial\Models\AdditionalCost::class)
                                ->whereIn('source_id', $supplierBilledCostIds)
                                ->withoutSideTags();
                        });
                    }
                    if ($creditNoteIds->isNotEmpty()) {
                        $q->orWhere(function ($q2) use ($creditNoteIds) {
                            $q2->where('payable_type', \App\Domain\Financial\Models\CreditNote::class)
                                ->whereIn('payable_id', $creditNoteIds);
                        });
                    }
                });
            }
        }

        return $query->with(['payable', 'shipment'])->get();
    }

    public static function buildOutstandingTable(Collection $items): string
    {
        $grouped = $items->groupBy(fn ($item) => $item->payable?->reference ?? 'Unknown');

        $html = '<div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Outstanding Items</div>';
        $html .= '<table class="w-full text-sm border-collapse">';
        $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Document</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Supplier Invoice</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Stage</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Due</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Paid</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Remaining</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Cur.</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Status</th>';
        $html .= '</tr></thead><tbody>';

        $totalRemaining = 0;

        foreach ($grouped as $docRef => $docItems) {
            foreach ($docItems as $item) {
                $remaining = $item->remaining_amount;
                $totalRemaining += $remaining;
                $statusColor = match ($item->status->value ?? $item->status) {
                    'due' => 'text-yellow-600',
                    'overdue' => 'text-red-600',
                    default => 'text-gray-500',
                };
                $statusLabel = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;

                $cleanLabel = e(static::cleanLabel($item));
                $shipRef = static::shipmentRef($item);

                $stageBadge = '<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-800 dark:bg-white/10 dark:text-gray-200">'.$cleanLabel.'</span>';
                if ($shipRef) {
                    $stageBadge .= ' <span class="inline-flex items-center rounded-md bg-blue-50 px-1.5 py-0.5 text-[0.65rem] font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">'.e($shipRef).'</span>';
                }

                // Show B/L number instead of SH reference when payable is Shipment
                $displayRef = $docRef;
                if ($item->payable instanceof Shipment && $item->payable->bl_number) {
                    $displayRef = $item->payable->bl_number;
                }

                $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
                $supplierInvoice = static::supplierInvoiceNumber($item) ?? '';

                $html .= '<td class="py-1.5 px-2 font-medium">'.e($displayRef).'</td>';
                $html .= '<td class="py-1.5 px-2">'.e($supplierInvoice).'</td>';
                $html .= '<td class="py-1.5 px-2">'.$stageBadge.'</td>';
                $html .= '<td class="py-1.5 px-2 text-right">'.Money::format($item->amount).'</td>';
                $html .= '<td class="py-1.5 px-2 text-right">'.Money::format($item->paid_amount).'</td>';
                $html .= '<td class="py-1.5 px-2 text-right font-semibold">'.Money::format($remaining).'</td>';
                $html .= '<td class="py-1.5 px-2">'.e($item->currency_code).'</td>';
                $html .= '<td class="py-1.5 px-2 '.$statusColor.' capitalize">'.e($statusLabel).'</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody>';
        $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
        $html .= '<td colspan="5" class="py-1.5 px-2 font-bold text-right">Total Remaining:</td>';
        $html .= '<td class="py-1.5 px-2 text-right font-bold text-primary-600">'.Money::format($totalRemaining).'</td>';
        $html .= '<td colspan="2"></td>';
        $html .= '</tr></tfoot></table>';

        return $html;
    }

    public static function buildCreditsTable(Collection $credits): string
    {
        $html = '<div class="text-xs font-semibold text-green-600 dark:text-green-400 uppercase tracking-wider mb-2">Available Credits</div>';
        $html .= '<table class="w-full text-sm border-collapse">';
        $html .= '<thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Document</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Description</th>';
        $html .= '<th class="text-right py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Credit Amount</th>';
        $html .= '<th class="text-left py-1.5 px-2 font-medium text-gray-600 dark:text-gray-400">Cur.</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($credits as $credit) {
            $docRef = static::formatDocRef($credit);
            $cleanLabel = e(static::cleanLabel($credit));

            $descBadge = '<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-800 dark:bg-white/10 dark:text-gray-200">'.$cleanLabel.'</span>';
            $descBadge .= ' <span class="inline-flex items-center rounded-md bg-green-50 px-1.5 py-0.5 text-[0.6rem] font-semibold text-green-700 uppercase dark:bg-green-400/10 dark:text-green-400">Credit</span>';

            $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
            $html .= '<td class="py-1.5 px-2 font-medium">'.e($docRef).'</td>';
            $html .= '<td class="py-1.5 px-2">'.$descBadge.'</td>';
            $html .= '<td class="py-1.5 px-2 text-right font-semibold text-green-600">'.Money::format($credit->credit_available_amount).'</td>';
            $html .= '<td class="py-1.5 px-2">'.e($credit->currency_code).'</td>';
            $html .= '</tr>';
        }

        $totalCredit = $credits->sum(fn ($credit) => $credit->credit_available_amount);
        $html .= '</tbody>';
        $html .= '<tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
        $html .= '<td colspan="2" class="py-1.5 px-2 font-bold text-right">Total Credit:</td>';
        $html .= '<td class="py-1.5 px-2 text-right font-bold text-green-600">'.Money::format($totalCredit).'</td>';
        $html .= '<td></td>';
        $html .= '</tr></tfoot></table>';

        return $html;
    }

    public static function recalculateTotal(Get $get, Set $set): void
    {
        $currentAmount = (float) ($get('../../amount') ?? $get('amount') ?? 0);

        // Don't overwrite manually entered amount
        if ($currentAmount > 0) {
            return;
        }

        $allocations = $get('../../allocations') ?? $get('allocations') ?? [];
        $total = 0;

        foreach ($allocations as $alloc) {
            $total += (float) ($alloc['allocated_amount'] ?? 0);
        }

        if ($total > 0) {
            $set('../../amount', number_format($total, 2, '.', ''));
        }
    }

    /**
     * Dual-currency allocation row schema. Each row holds four live-linked
     * fields: schedule item, amount in payment currency, amount in document
     * currency, and exchange rate. The three amount fields are
     * algebraically tied by doc = pmt × rate and cross-update whenever one
     * of them changes. The fields for document currency and exchange rate
     * are hidden when the payment and document currencies coincide.
     */
    protected static function allocationRepeaterSchema(PaymentDirection $direction): array
    {
        return [
            Hidden::make('document_currency_code'),

            Select::make('payment_schedule_item_id')
                ->label(__('forms.labels.schedule_item'))
                ->options(function (Get $get) use ($direction) {
                    $companyId = $get('../../company_id');
                    if (! $companyId) {
                        return [];
                    }

                    return static::getCompanyScheduleItems((int) $companyId, $direction)
                        ->mapWithKeys(fn ($item) => [
                            $item->id => static::formatScheduleItemLabel($item),
                        ]);
                })
                ->getOptionLabelUsing(function ($value): ?string {
                    $item = PaymentScheduleItem::with('payable')->find($value);

                    return $item ? static::formatScheduleItemLabel($item) : null;
                })
                ->required()
                ->distinct()
                ->searchable()
                ->live()
                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                    if (! $state) {
                        return;
                    }
                    static::prefillAllocationRowForItem((int) $state, $get, $set);
                    static::recalculateTotal($get, $set);
                })
                ->columnSpan(12),

            TextInput::make('allocated_amount')
                ->label(fn (Get $get) => static::allocationFieldLabel(
                    __('forms.labels.amount'),
                    (string) ($get('../../currency_code') ?? '')
                ))
                ->numeric()
                ->step('0.01')
                ->minValue(0.01)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::syncAllocationRow($get, $set, 'allocated_amount');
                    static::recalculateTotal($get, $set);
                })
                ->columnSpan(4),

            TextInput::make('allocated_amount_in_document_currency')
                ->label(fn (Get $get) => static::allocationFieldLabel(
                    __('forms.labels.in_document_currency'),
                    (string) ($get('document_currency_code') ?? '')
                ))
                ->numeric()
                ->step('0.01')
                ->minValue(0.01)
                ->live(onBlur: true)
                ->visible(fn (Get $get) => static::rowHasDifferingCurrencies($get))
                ->required(fn (Get $get) => static::rowHasDifferingCurrencies($get))
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::syncAllocationRow($get, $set, 'allocated_amount_in_document_currency');
                })
                ->columnSpan(4),

            TextInput::make('exchange_rate')
                ->label(fn (Get $get) => static::exchangeRateLabel($get))
                ->numeric()
                ->step('0.00000001')
                ->live(onBlur: true)
                ->visible(fn (Get $get) => static::rowHasDifferingCurrencies($get))
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::syncAllocationRow($get, $set, 'exchange_rate');
                    $set('exchange_rate_captured_at', static::paymentDateFromForm($get) ?? now()->toDateString());
                })
                ->helperText(function (Get $get) {
                    $captured = $get('exchange_rate_captured_at');
                    if (! $captured) {
                        return null;
                    }

                    return __('forms.helpers.fx_rate_captured_on', [
                        'date' => \Carbon\Carbon::parse($captured)->format('d/m/Y'),
                    ]);
                })
                ->columnSpan(4),

            \Filament\Forms\Components\Hidden::make('exchange_rate_captured_at'),
        ];
    }

    /**
     * Payment date of the form (allocation rows live two levels below the
     * root), normalized to Y-m-d. Null when the form has no such field or
     * it is still empty.
     */
    protected static function paymentDateFromForm(Get $get): ?string
    {
        $raw = $get('../../payment_date');

        if (blank($raw)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pre-fill the three amount fields and the hidden currency tracker when
     * a schedule item is selected. Uses the ExchangeRate effective on the
     * payment date (not today) when the currencies differ; when no approved
     * rate exists, the payment-currency amount and the rate are left blank
     * for the user to enter manually (no silent fallback).
     */
    protected static function prefillAllocationRowForItem(int $itemId, Get $get, Set $set): void
    {
        $item = PaymentScheduleItem::find($itemId);
        if (! $item) {
            return;
        }

        $docCurrency = (string) $item->currency_code;
        $pmtCurrency = (string) ($get('../../currency_code') ?? '');
        $remainingDocMajor = Money::toMajor($item->remaining_amount);

        $set('document_currency_code', $docCurrency);

        if ($pmtCurrency === '' || $pmtCurrency === $docCurrency) {
            $set('allocated_amount', number_format($remainingDocMajor, 2, '.', ''));
            $set('allocated_amount_in_document_currency', number_format($remainingDocMajor, 2, '.', ''));
            $set('exchange_rate', null);

            return;
        }

        $set('allocated_amount_in_document_currency', number_format($remainingDocMajor, 2, '.', ''));

        $paymentDate = static::paymentDateFromForm($get);
        $resolved = AllocationCalculator::lookupRateWithDate($pmtCurrency, $docCurrency, $paymentDate);
        $rate = $resolved['rate'];

        if ($rate !== null && $rate > 0) {
            $set('exchange_rate', number_format($rate, 8, '.', ''));
            $set('allocated_amount', number_format($remainingDocMajor / $rate, 2, '.', ''));
            $set('exchange_rate_captured_at', $resolved['date'] ?? $paymentDate ?? now()->toDateString());
        } else {
            $set('exchange_rate', null);
            $set('allocated_amount', null);
            $set('exchange_rate_captured_at', null);
        }
    }

    /**
     * Keep the three tied values coherent while the user types in any of
     * them. The rule is: doc = pmt × rate. Whichever field the user just
     * changed determines which of the remaining two is recomputed, choosing
     * the branch that preserves the most user input.
     */
    protected static function syncAllocationRow(Get $get, Set $set, string $changedField): void
    {
        $pmtCurrency = (string) ($get('../../currency_code') ?? '');
        $docCurrency = (string) ($get('document_currency_code') ?? '');

        $pmt = (float) ($get('allocated_amount') ?? 0);
        $doc = (float) ($get('allocated_amount_in_document_currency') ?? 0);
        $rate = (float) ($get('exchange_rate') ?? 0);

        // Same currency (or doc currency unknown): mirror pmt <-> doc, clear rate.
        if ($pmtCurrency === '' || $docCurrency === '' || $pmtCurrency === $docCurrency) {
            if ($changedField === 'allocated_amount' && $pmt > 0) {
                $set('allocated_amount_in_document_currency', number_format($pmt, 2, '.', ''));
            } elseif ($changedField === 'allocated_amount_in_document_currency' && $doc > 0) {
                $set('allocated_amount', number_format($doc, 2, '.', ''));
            }
            $set('exchange_rate', null);

            return;
        }

        switch ($changedField) {
            case 'allocated_amount':
                if ($rate > 0 && $pmt > 0) {
                    $set('allocated_amount_in_document_currency', number_format($pmt * $rate, 2, '.', ''));
                } elseif ($pmt > 0 && $doc > 0) {
                    $set('exchange_rate', number_format($doc / $pmt, 8, '.', ''));
                }
                break;

            case 'allocated_amount_in_document_currency':
                if ($rate > 0 && $doc > 0) {
                    $set('allocated_amount', number_format($doc / $rate, 2, '.', ''));
                } elseif ($pmt > 0 && $doc > 0) {
                    $set('exchange_rate', number_format($doc / $pmt, 8, '.', ''));
                }
                break;

            case 'exchange_rate':
                if ($rate > 0 && $pmt > 0) {
                    $set('allocated_amount_in_document_currency', number_format($pmt * $rate, 2, '.', ''));
                } elseif ($rate > 0 && $doc > 0) {
                    $set('allocated_amount', number_format($doc / $rate, 2, '.', ''));
                }
                break;
        }
    }

    protected static function rowHasDifferingCurrencies(Get $get): bool
    {
        $pmtCurrency = (string) ($get('../../currency_code') ?? '');
        $docCurrency = (string) ($get('document_currency_code') ?? '');

        if ($pmtCurrency === '' || $docCurrency === '') {
            return false;
        }

        return $pmtCurrency !== $docCurrency;
    }

    protected static function allocationFieldLabel(string $base, string $currencyCode): string
    {
        return $currencyCode !== '' ? "{$base} ({$currencyCode})" : $base;
    }

    protected static function exchangeRateLabel(Get $get): string
    {
        $pmt = (string) ($get('../../currency_code') ?? '');
        $doc = (string) ($get('document_currency_code') ?? '');

        $base = __('forms.labels.exchange_rate');

        if ($pmt !== '' && $doc !== '' && $pmt !== $doc) {
            return "{$base} ({$pmt} → {$doc})";
        }

        return $base;
    }

    public static function buildAllocationSummary(array $allocations, float $wireAmount, string $currency): string
    {
        $totalAllocated = 0.0;
        $docTotals = [];
        $incompleteRows = 0;

        foreach ($allocations as $alloc) {
            $pmt = (float) ($alloc['allocated_amount'] ?? 0);
            $totalAllocated += $pmt;

            $docCurrency = (string) ($alloc['document_currency_code'] ?? '');
            $docAmount = (float) ($alloc['allocated_amount_in_document_currency'] ?? 0);

            if ($docCurrency !== '' && $docCurrency !== $currency && $docAmount > 0) {
                $docTotals[$docCurrency] = ($docTotals[$docCurrency] ?? 0.0) + $docAmount;
            }

            if ($docCurrency !== '' && $docCurrency !== $currency) {
                $rate = (float) ($alloc['exchange_rate'] ?? 0);
                $present = (int) ($pmt > 0) + (int) ($docAmount > 0) + (int) ($rate > 0);
                if ($present < 2) {
                    $incompleteRows++;
                }
            }
        }

        $remaining = $wireAmount - $totalAllocated;

        // Inline styles: the panel's precompiled CSS may not include these
        // utility classes, which renders the segments glued together.
        $html = '<div class="text-sm" style="display:flex;flex-wrap:wrap;column-gap:1.5rem;row-gap:0.25rem;">';
        $html .= '<span class="text-gray-500">Wire Transfer: <span class="font-semibold text-gray-900 dark:text-white">'
            .e($currency).' '.number_format($wireAmount, 2).'</span></span>';
        $html .= '<span class="text-gray-500">Allocated: <span class="font-semibold text-blue-600">'
            .e($currency).' '.number_format($totalAllocated, 2).'</span></span>';

        if ($wireAmount > 0 && abs($remaining) > 0.001) {
            if ($remaining > 0) {
                $html .= '<span class="text-gray-500">Remaining: <span class="font-semibold text-yellow-600">'
                    .e($currency).' '.number_format($remaining, 2).'</span></span>';
            } else {
                $html .= '<span class="text-gray-500">Over-allocated: <span class="font-semibold text-red-600">'
                    .e($currency).' '.number_format(abs($remaining), 2).'</span></span>';
            }
        } elseif ($wireAmount > 0) {
            $html .= '<span class="font-semibold text-green-600">Fully Allocated</span>';
        }

        foreach ($docTotals as $code => $total) {
            $html .= '<span class="text-gray-500">Applied in '.e($code).': <span class="font-semibold text-gray-900 dark:text-white">'
                .e($code).' '.number_format($total, 2).'</span></span>';
        }

        if ($incompleteRows > 0) {
            $html .= '<span class="font-semibold text-red-600">'.(int) $incompleteRows
                .' allocation(s) missing currency conversion data</span>';
        }

        $html .= '</div>';

        return $html;
    }

    public static function cleanLabel(PaymentScheduleItem $item): string
    {
        return preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $item->label ?? '');
    }

    public static function shipmentRef(PaymentScheduleItem $item): ?string
    {
        // Check the direct shipment relation first
        $shipment = $item->relationLoaded('shipment') ? $item->shipment : $item->shipment()->first();

        // If no direct shipment, check if the payable itself is a Shipment
        if (! $shipment && $item->payable instanceof Shipment) {
            $shipment = $item->payable;
        }

        return $shipment ? ($shipment->bl_number ?: null) : null;
    }

    public static function formatDocRef(PaymentScheduleItem $item): string
    {
        $payable = $item->payable;
        if ($payable instanceof Shipment && $payable->bl_number) {
            return $payable->bl_number;
        }

        return $payable?->reference ?? 'Unknown';
    }

    public static function formatScheduleItemLabel(PaymentScheduleItem $item): string
    {
        $docRef = static::formatDocRef($item);
        $label = static::cleanLabel($item);
        $remaining = Money::format($item->remaining_amount);
        $shipRef = static::shipmentRef($item);
        $supplierInvoice = static::supplierInvoiceNumber($item);
        $clientRef = $item->payable?->client_reference ?: null;

        $parts = "[{$docRef}] {$label}";
        if ($supplierInvoice) {
            $parts .= " (Inv: {$supplierInvoice})";
        }
        if ($clientRef) {
            $parts .= " (Ref: {$clientRef})";
        }
        if ($shipRef) {
            $parts .= " [{$shipRef}]";
        }
        $parts .= " — {$item->currency_code} {$remaining} remaining";

        return $parts;
    }

    public static function supplierInvoiceNumber(PaymentScheduleItem $item): ?string
    {
        $payable = $item->payable;

        if ($payable instanceof PurchaseOrder) {
            return $payable->supplier_invoice_number ?: null;
        }

        return null;
    }

    /**
     * Teto (em unidades maiores) que uma NOVA aplicação de crédito pode
     * reservar no form — saldo disponível contando pagamentos pendentes.
     */
    public static function maxApplicableCreditMajor(PaymentScheduleItem $creditItem, ?int $ignorePaymentId = null): float
    {
        $available = $creditItem->credit_available_amount;

        if ($ignorePaymentId !== null) {
            // No Edit, as aplicações do PRÓPRIO pagamento serão recriadas —
            // a capacidade que elas ocupam volta a estar disponível.
            $own = (int) $creditItem->creditAllocations()
                ->where('payment_id', $ignorePaymentId)
                ->sum('allocated_amount_in_document_currency');

            $available = min($creditItem->amount, $available + $own);
        }

        return Money::toMajor($available);
    }

    public static function formatCreditItemLabel(PaymentScheduleItem $item): string
    {
        $docRef = static::formatDocRef($item);
        $label = static::cleanLabel($item);

        $available = $item->credit_available_amount;

        if ($available < $item->amount) {
            $availableFormatted = Money::format($available);
            $totalFormatted = Money::format($item->amount);

            return "[{$docRef}] {$label} — {$item->currency_code} {$availableFormatted} available of {$totalFormatted} credit";
        }

        $amount = Money::format($item->amount);

        return "[{$docRef}] {$label} — {$item->currency_code} {$amount} credit";
    }
}
