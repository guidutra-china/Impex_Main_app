<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Actions\WaivePaymentScheduleItemAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Settings\Models\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PaymentScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentScheduleItems';

    protected static ?string $title = 'Payment Schedule';

    protected static BackedEnum|string|null $icon = 'heroicon-o-calendar-days';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('label')
                    ->label(__('forms.labels.description'))
                    ->formatStateUsing(function ($state, $record) {
                        $label = preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $state ?? '');
                        $label = e($label);

                        $isForwarderPayable = str_contains($record->notes ?? '', '[forwarder-payable]');

                        if ($isForwarderPayable) {
                            $badgeClass = 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30';
                        } else {
                            $badgeClass = 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/30';
                        }

                        $html = '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold '.$badgeClass.'">'.$label.'</span>';

                        $directionLabel = $isForwarderPayable ? 'OUT' : 'IN';
                        $directionClass = $isForwarderPayable
                            ? 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400'
                            : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400';
                        $html .= ' <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase '.$directionClass.'">'.$directionLabel.'</span>';

                        $record->loadMissing('shipment');
                        if ($record->shipment) {
                            $ref = e($record->shipment->bl_number ?: $record->shipment->reference);
                            $html .= ' <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[0.65rem] font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">'.$ref.'</span>';
                        }

                        // When owner is a Shipment, surface the related PI/PO reference and
                        // its client_reference so users can tell which document the schedule
                        // item belongs to (a shipment may carry items from several PIs/POs).
                        if ($this->getOwnerRecord() instanceof Shipment) {
                            [$docRef, $clientRef] = $this->resolveDocContext($record);
                            $docType = $this->resolveDocType($record, $docRef);

                            if ($docType !== null) {
                                $typeBadgeClass = $docType === 'PO'
                                    ? 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-400 dark:ring-orange-400/30'
                                    : 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-400/10 dark:text-purple-400 dark:ring-purple-400/30';
                                $html .= ' <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6rem] font-bold uppercase '.$typeBadgeClass.'">'.e($docType).'</span>';
                            }
                            if ($docRef) {
                                $refBadgeClass = $docType === 'PO'
                                    ? 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-400 dark:ring-orange-400/30'
                                    : 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-400/10 dark:text-purple-400 dark:ring-purple-400/30';
                                $html .= ' <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[0.65rem] font-semibold '.$refBadgeClass.'">'.e($docRef).'</span>';
                            }
                            if ($clientRef) {
                                $html .= ' <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[0.65rem] font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/30" title="Client Reference">Ref: '.e($clientRef).'</span>';
                            }
                        }

                        if ($record->is_credit) {
                            $html .= ' <span class="inline-flex items-center rounded-md bg-green-50 px-1.5 py-0.5 text-[0.6rem] font-semibold text-green-700 uppercase dark:bg-green-400/10 dark:text-green-400">Credit</span>';
                        }

                        return new HtmlString($html);
                    }),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state, $record) => $record->is_credit
                        ? '-'.Money::format($state)
                        : Money::format($state))
                    ->prefix(fn ($record) => $this->getCurrencySymbol($record->currency_code).' ')
                    ->alignEnd()
                    ->color(fn ($record) => $record->is_credit ? 'success' : null),
                TextColumn::make('paid_amount')
                    ->label(__('forms.labels.paid'))
                    ->getStateUsing(fn ($record) => $record->paid_amount)
                    ->formatStateUsing(function ($state, $record) {
                        $formatted = Money::format($state);
                        if ($record->is_overpaid) {
                            $overpaid = Money::format($record->overpaid_amount);

                            return $formatted.' ⚠ +'.$overpaid;
                        }

                        return $formatted;
                    })
                    ->prefix(fn ($record) => $this->getCurrencySymbol($record->currency_code).' ')
                    ->alignEnd()
                    ->tooltip(fn ($record) => $record->is_overpaid
                        ? 'Overpaid by '.$this->getCurrencySymbol($record->currency_code).' '.Money::format($record->overpaid_amount)
                        : null)
                    ->color(fn ($record) => $record->is_overpaid ? 'warning' : 'success'),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining'))
                    ->getStateUsing(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix(fn ($record) => $this->getCurrencySymbol($record->currency_code).' ')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('due_condition')
                    ->label(__('forms.labels.condition'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y')
                    ->placeholder(__('forms.placeholders.tbd'))
                    ->color(fn ($record) => $record->due_date?->isPast() && ! $record->status->isResolved() ? 'danger' : null),
                TextColumn::make('is_blocking')
                    ->label(__('forms.labels.blocking'))
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->formatStateUsing(function ($state, $record) {
                        $label = $state instanceof \BackedEnum ? ($state->getLabel() ?? $state->value) : (string) $state;

                        return $record->is_overpaid ? $label.' · Overpaid' : $label;
                    })
                    ->color(fn ($state, $record) => $record->is_overpaid
                        ? 'warning'
                        : ($state instanceof \Filament\Support\Contracts\HasColor ? $state->getColor() : 'gray'))
                    ->icon(fn ($record) => $record->is_overpaid ? 'heroicon-o-exclamation-triangle' : null)
                    ->description(function ($record) {
                        if ($record->is_overpaid) {
                            return 'Overpaid by '.$this->getCurrencySymbol($record->currency_code).' '.Money::format($record->overpaid_amount);
                        }

                        return $record->is_credit && $record->is_credit_applied ? 'Credit Applied' : null;
                    }),
            ])
            ->headerActions([
                $this->generateScheduleAction(),
                $this->regenerateScheduleAction(),
            ])
            ->recordActions([
                $this->setDueDateAction(),
                $this->waiveAction(),
                $this->restoreWaivedAction(),
                $this->deleteScheduleItemAction(),
            ])
            ->emptyStateHeading('No payment schedule')
            ->emptyStateDescription('Generate a payment schedule from the payment terms.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    /**
     * Resolve [docReference, clientReference] for a schedule item belonging
     * to a Shipment. Tries the polymorphic payable first (PO PSI items have
     * payable_type=PurchaseOrder), then falls back to extracting from the
     * label suffix (Shipment-direct PI items embed PI ref in the label).
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveDocContext($record): array
    {
        static $cache = [];

        $payableType = $record->payable_type;
        if ($payableType === \App\Domain\PurchaseOrders\Models\PurchaseOrder::class) {
            $record->loadMissing('payable');
            $docRef = $record->payable?->reference;

            return [$docRef, null];
        }

        if (! $record->label || ! preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', $record->label, $m)) {
            return [null, null];
        }

        $docRef = $m[1];
        if (array_key_exists($docRef, $cache)) {
            return [$docRef, $cache[$docRef]];
        }

        // client_reference only exists on proforma_invoices — not on purchase_orders.
        $isPi = str_starts_with($docRef, 'PI');
        $clientRef = $isPi
            ? \App\Domain\ProformaInvoices\Models\ProformaInvoice::where('reference', $docRef)->value('client_reference')
            : null;

        $cache[$docRef] = $clientRef;

        return [$docRef, $clientRef];
    }

    /**
     * Resolve the document type label (PI or PO) for badge rendering.
     * Prefers payable_type (authoritative), falls back to docRef prefix.
     */
    protected function resolveDocType($record, ?string $docRef): ?string
    {
        if ($record->payable_type === \App\Domain\PurchaseOrders\Models\PurchaseOrder::class) {
            return 'PO';
        }

        if ($record->payable_type === \App\Domain\ProformaInvoices\Models\ProformaInvoice::class) {
            return 'PI';
        }

        if ($docRef === null) {
            return null;
        }

        return str_starts_with($docRef, 'PO') ? 'PO' : 'PI';
    }

    protected function getCurrencySymbol(?string $currencyCode): string
    {
        static $cache = [];

        if (! $currencyCode) {
            return '$';
        }

        if (! isset($cache[$currencyCode])) {
            $currency = Currency::where('code', $currencyCode)->first();
            $cache[$currencyCode] = $currency?->symbol ?? $currencyCode;
        }

        return $cache[$currencyCode];
    }

    protected function generateScheduleAction(): Action
    {
        return Action::make('generateSchedule')
            ->label(__('forms.labels.generate_schedule'))
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('generate-payment-schedule'))
            ->requiresConfirmation()
            ->modalHeading('Generate Payment Schedule')
            ->modalDescription(function () {
                $record = $this->getOwnerRecord();

                if ($record instanceof Shipment) {
                    return $this->getShipmentScheduleDescription($record);
                }

                $paymentTerm = $record->paymentTerm;

                if (! $paymentTerm) {
                    return 'No payment term is assigned to this document. Please assign a payment term first.';
                }

                if ($record->hasPaymentSchedule()) {
                    return 'A payment schedule already exists. Use "Regenerate" to update it.';
                }

                $stages = $paymentTerm->stages;
                $lines = $stages->map(fn ($s) => $s->percentage.'% — '.($s->calculation_base?->getLabel() ?? 'N/A').($s->days > 0 ? ' (+'.$s->days.' days)' : ''));

                return 'This will generate a schedule based on "'.$paymentTerm->name.'":'
                    ."\n".$lines->implode("\n")
                    ."\n\nTotal: ".Money::format($record->total).' '.$record->currency_code;
            })
            ->action(function () {
                $record = $this->getOwnerRecord();

                if ($record instanceof Shipment) {
                    if ($record->hasPaymentSchedule()) {
                        Notification::make()->title('Schedule already exists')->warning()->send();

                        return;
                    }

                    $count = app(GeneratePaymentScheduleAction::class)->executeForShipment($record);
                } else {
                    if (! $record->payment_term_id) {
                        Notification::make()->title('No payment term assigned')->danger()->send();

                        return;
                    }

                    if ($record->hasPaymentSchedule()) {
                        Notification::make()->title('Schedule already exists')->warning()->send();

                        return;
                    }

                    $count = app(GeneratePaymentScheduleAction::class)->execute($record);
                }

                if ($count === 0) {
                    Notification::make()
                        ->title(__('messages.no_schedule_items_created'))
                        ->body(__('messages.schedule_items_shipment_dependent'))
                        ->warning()
                        ->send();
                } else {
                    Notification::make()
                        ->title($count.' schedule items generated')
                        ->success()
                        ->send();
                }
            });
    }

    protected function regenerateScheduleAction(): Action
    {
        return Action::make('regenerateSchedule')
            ->label(__('forms.labels.regenerate'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Regenerate Payment Schedule')
            ->modalDescription('This will delete unpaid/unwaived schedule items without payments and recreate them from the current payment terms and total. Paid and waived items will be preserved.')
            ->visible(fn () => $this->getOwnerRecord()->hasPaymentSchedule() && auth()->user()?->can('generate-payment-schedule'))
            ->action(function () {
                $record = $this->getOwnerRecord();

                $count = $record instanceof Shipment
                    ? app(GeneratePaymentScheduleAction::class)->regenerateForShipment($record)
                    : app(GeneratePaymentScheduleAction::class)->regenerate($record);

                Notification::make()
                    ->title($count.' schedule items regenerated')
                    ->success()
                    ->send();
            });
    }

    protected function setDueDateAction(): Action
    {
        return Action::make('setDueDate')
            ->label(__('forms.labels.set_due_date'))
            ->icon('heroicon-o-calendar')
            ->color('info')
            ->form([
                DatePicker::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->required(),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved()
                && auth()->user()?->can('edit-payments'))
            ->action(function ($record, array $data) {
                $record->update(['due_date' => $data['due_date']]);

                Notification::make()->title('Due date updated')->success()->send();
            });
    }

    protected function waiveAction(): Action
    {
        return Action::make('waive')
            ->label(__('forms.labels.waive'))
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Waive Payment')
            ->modalDescription(fn ($record) => 'This will waive the payment "'.$record->label.'" ('.Money::format($record->amount).' '.$record->currency_code.'). The blocking condition will be removed.')
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.reason_for_waiving'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved() && auth()->user()?->can('waive-payments'))
            ->action(function ($record, array $data) {
                app(WaivePaymentScheduleItemAction::class)->execute($record, $data['reason'] ?? null);

                Notification::make()->title('Payment waived')->success()->send();
            });
    }

    protected function restoreWaivedAction(): Action
    {
        return Action::make('restoreWaived')
            ->label(__('forms.labels.restore'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.restore_payment'))
            ->modalDescription(fn ($record) => 'This will restore the waived payment "'.$record->label.'" back to pending status.')
            ->visible(fn ($record) => $record->status === PaymentScheduleStatus::WAIVED && auth()->user()?->can('waive-payments'))
            ->action(function ($record) {
                $record->update([
                    'status' => PaymentScheduleStatus::PENDING,
                    'waived_by' => null,
                    'waived_at' => null,
                ]);

                Notification::make()->title(__('messages.payment_restored'))->success()->send();
            });
    }

    protected function deleteScheduleItemAction(): Action
    {
        return Action::make('deleteItem')
            ->label(__('forms.labels.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.delete'))
            ->modalDescription('This will permanently delete this schedule item.')
            ->visible(fn ($record) => ! $record->allocations()->exists()
                && ! in_array($record->status, [PaymentScheduleStatus::PAID])
                && auth()->user()?->can('generate-payment-schedule'))
            ->action(function ($record) {
                $record->delete();

                Notification::make()->title('Schedule item deleted')->success()->send();
            });
    }

    protected function getShipmentScheduleDescription(Shipment $shipment): string
    {
        $shipment->loadMissing(['items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages']);

        if ($shipment->hasPaymentSchedule()) {
            return 'A payment schedule already exists. Use "Regenerate" to update it.';
        }

        $itemsByPi = $shipment->items
            ->filter(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice)
            ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id);

        if ($itemsByPi->isEmpty()) {
            return 'No items linked to Proforma Invoices. Add items from a PI first.';
        }

        $lines = [];
        $grandTotal = 0;

        foreach ($itemsByPi as $piId => $shipmentItems) {
            $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;
            $paymentTerm = $pi->paymentTerm;
            $piValue = $shipmentItems->sum(fn ($item) => $item->proformaInvoiceItem->unit_price * $item->quantity);
            $grandTotal += $piValue;

            $piRef = $pi->reference.($pi->client_reference ? ' — '.$pi->client_reference : '');

            if (! $paymentTerm) {
                $lines[] = "**{$piRef}**: ".Money::format($piValue).' — ⚠ No payment term assigned to this PI';

                continue;
            }

            $shipmentStages = $paymentTerm->stages
                ->filter(fn ($s) => $s->calculation_base?->isShipmentDependent())
                ->map(fn ($s) => $s->percentage.'% '.$s->calculation_base->getLabel())
                ->implode(', ');

            if (empty($shipmentStages)) {
                $lines[] = "**{$piRef}**: ".Money::format($piValue).' — ⚠ No shipment-dependent stages in payment term';

                continue;
            }

            $lines[] = "**{$piRef}**: ".Money::format($piValue)." — {$shipmentStages}";
        }

        return "Only shipment-dependent payment stages will be generated (non-shipment stages like upfront remain on the PI schedule).\n\n"
            .implode("\n", $lines)
            ."\n\nShipment Value: ".Money::format($grandTotal).' '.($shipment->currency_code ?? '');
    }
}
