<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadReceiptAction(),
            $this->manageAllocationsAction(),
            $this->approveAction(),
            $this->rejectAction(),
            $this->cancelAction(),
            EditAction::make()
                ->visible(fn () => in_array($this->record->status, [
                    PaymentStatus::PENDING_APPROVAL,
                    PaymentStatus::REJECTED,
                ])),
        ];
    }

    protected function manageAllocationsAction(): Action
    {
        return Action::make('manageAllocations')
            ->label(__('forms.labels.manage_allocations'))
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn () => $this->record->status === PaymentStatus::APPROVED
                && $this->record->unallocated_amount > 0)
            ->modalHeading(__('forms.labels.manage_allocations'))
            ->modalDescription(fn () => new HtmlString(
                '<div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">'
                .'<span class="text-gray-500">'.__('forms.labels.total_amount').': <span class="font-semibold text-gray-900 dark:text-white">'
                .$this->record->currency_code.' '.Money::format($this->record->amount).'</span></span>'
                .'<span class="text-gray-500">'.__('forms.labels.already_allocated').': <span class="font-semibold text-blue-600">'
                .$this->record->currency_code.' '.Money::format($this->record->allocated_total).'</span></span>'
                .'<span class="text-gray-500">'.__('forms.labels.available_to_allocate').': <span class="font-semibold text-yellow-600">'
                .$this->record->currency_code.' '.Money::format($this->record->unallocated_amount).'</span></span>'
                .'</div>'
            ))
            ->modalWidth('4xl')
            ->form(fn () => [
                Repeater::make('new_allocations')
                    ->label('')
                    ->schema([
                        Select::make('payment_schedule_item_id')
                            ->label(__('forms.labels.schedule_item'))
                            ->options(function () {
                                $payment = $this->record;
                                $items = PaymentForm::getCompanyScheduleItems(
                                    $payment->company_id,
                                    $payment->direction
                                );

                                return $items->mapWithKeys(function ($item) {
                                    $clientRef = $item->payable?->client_reference;
                                    $label = '['.($item->payable?->reference ?? '?').'] '
                                        .($item->label ?? $item->paymentTermStage?->name ?? '—');
                                    if ($clientRef) {
                                        $label .= " (Ref: {$clientRef})";
                                    }
                                    $label .= ' — '.$item->currency_code.' '.Money::format($item->remaining_amount)
                                        .' remaining';

                                    return [$item->id => $label];
                                });
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                $item = PaymentScheduleItem::with('payable', 'paymentTermStage')->find($value);
                                if (! $item) {
                                    return null;
                                }

                                $clientRef = $item->payable?->client_reference;
                                $labelText = '['.($item->payable?->reference ?? '?').'] '
                                    .($item->label ?? $item->paymentTermStage?->name ?? '—');
                                if ($clientRef) {
                                    $labelText .= " (Ref: {$clientRef})";
                                }
                                $labelText .= ' — '.$item->currency_code.' '.Money::format($item->remaining_amount)
                                    .' remaining';

                                return $labelText;
                            })
                            ->required()
                            ->distinct()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if (! $state) {
                                    return;
                                }
                                $item = PaymentScheduleItem::find($state);
                                if ($item) {
                                    $set('allocated_amount', number_format(Money::toMajor($item->remaining_amount), 2, '.', ''));
                                }
                            })
                            ->columnSpan(5),
                        TextInput::make('allocated_amount')
                            ->label(__('forms.labels.amount'))
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0.01)
                            ->required()
                            ->columnSpan(3),
                        TextInput::make('exchange_rate')
                            ->label(__('forms.labels.exchange_rate'))
                            ->numeric()
                            ->step('0.00000001')
                            ->placeholder(__('forms.placeholders.auto'))
                            ->columnSpan(2),
                    ])
                    ->columns(10)
                    ->defaultItems(1)
                    ->addActionLabel('+ '.__('forms.labels.add_allocation')),
            ])
            ->action(function (array $data) {
                $payment = $this->record;
                $paymentCurrencyCode = $payment->currency_code;
                $newAllocations = $data['new_allocations'] ?? [];

                $totalNewAllocation = 0;
                foreach ($newAllocations as $alloc) {
                    $totalNewAllocation += Money::toMinor((float) ($alloc['allocated_amount'] ?? 0));
                }

                if ($totalNewAllocation > $payment->unallocated_amount) {
                    Notification::make()
                        ->title(__('messages.allocation_exceeds_available'))
                        ->body(
                            __('messages.available_amount').': '
                            .$paymentCurrencyCode.' '.Money::format($payment->unallocated_amount)
                        )
                        ->danger()
                        ->send();

                    $this->halt();

                    return;
                }

                foreach ($newAllocations as $allocationData) {
                    $scheduleItemId = $allocationData['payment_schedule_item_id'] ?? null;

                    if (! $scheduleItemId) {
                        continue;
                    }

                    $scheduleItem = PaymentScheduleItem::find($scheduleItemId);

                    if (! $scheduleItem) {
                        continue;
                    }

                    $allocatedMinor = Money::toMinor((float) ($allocationData['allocated_amount'] ?? 0));

                    if ($allocatedMinor <= 0) {
                        continue;
                    }

                    $exchangeRate = ! empty($allocationData['exchange_rate']) ? (float) $allocationData['exchange_rate'] : null;
                    $documentCurrencyCode = $scheduleItem->currency_code;
                    $allocatedInDocCurrency = $allocatedMinor;

                    if ($paymentCurrencyCode !== $documentCurrencyCode) {
                        if ($exchangeRate) {
                            $allocatedInDocCurrency = (int) round($allocatedMinor * $exchangeRate);
                        } else {
                            $paymentCurrency = Currency::where('code', $paymentCurrencyCode)->first();
                            $documentCurrency = Currency::where('code', $documentCurrencyCode)->first();

                            if ($paymentCurrency && $documentCurrency) {
                                $converted = ExchangeRate::convert(
                                    $paymentCurrency->id,
                                    $documentCurrency->id,
                                    Money::toMajor($allocatedMinor)
                                );

                                if ($converted !== null) {
                                    $allocatedInDocCurrency = Money::toMinor($converted);
                                    $exchangeRate = $allocatedInDocCurrency / max($allocatedMinor, 1);
                                }
                            }
                        }
                    }

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'payment_schedule_item_id' => $scheduleItemId,
                        'allocated_amount' => $allocatedMinor,
                        'exchange_rate' => $exchangeRate,
                        'allocated_amount_in_document_currency' => $allocatedInDocCurrency,
                    ]);

                    // Recalculate schedule item status since payment is already approved
                    if ($scheduleItem->status !== PaymentScheduleStatus::WAIVED) {
                        $scheduleItem->refresh();
                        $scheduleItem->update([
                            'status' => $scheduleItem->is_paid_in_full
                                ? PaymentScheduleStatus::PAID
                                : PaymentScheduleStatus::DUE,
                        ]);
                    }
                }

                Notification::make()
                    ->title(__('messages.allocations_saved'))
                    ->success()
                    ->send();

                $this->refreshFormData([
                    'allocated_total',
                    'unallocated_amount',
                ]);
            });
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('forms.labels.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Payment')
            ->modalDescription(fn () => 'Approve payment of '
                .Money::format($this->record->amount).' '
                .$this->record->currency_code.' to/from '
                .($this->record->company?->name ?? 'Unknown').'?')
            ->visible(fn () => $this->record->status === PaymentStatus::PENDING_APPROVAL
                && auth()->user()?->can('approve-payments'))
            ->action(function () {
                app(ApprovePaymentAction::class)->approve($this->record);

                Notification::make()->title(__('messages.payment_approved'))->success()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at']);
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('forms.labels.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject Payment')
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.rejection_reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn () => $this->record->status === PaymentStatus::PENDING_APPROVAL
                && auth()->user()?->can('reject-payments'))
            ->action(function (array $data) {
                app(ApprovePaymentAction::class)->reject($this->record, $data['reason']);

                Notification::make()->title(__('messages.payment_rejected'))->danger()->send();

                $this->refreshFormData(['status', 'notes']);
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('forms.labels.cancel_payment'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel Payment')
            ->modalDescription(fn () => 'Cancel payment of '
                .Money::format($this->record->amount).' '
                .$this->record->currency_code.'? '
                .($this->record->status === PaymentStatus::APPROVED
                    ? 'This will reverse the effect on schedule item statuses.'
                    : ''))
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.cancellation_reason'))
                    ->rows(2),
            ])
            ->visible(fn () => in_array($this->record->status, [
                PaymentStatus::PENDING_APPROVAL,
                PaymentStatus::APPROVED,
                PaymentStatus::REJECTED,
            ]) && auth()->user()?->can('delete', $this->record))
            ->action(function (array $data) {
                app(ApprovePaymentAction::class)->cancel($this->record, $data['reason'] ?? null);

                Notification::make()->title('Payment cancelled')->warning()->send();

                $this->refreshFormData(['status', 'notes']);
            });
    }

    protected function downloadReceiptAction(): Action
    {
        return Action::make('downloadReceipt')
            ->label(__('forms.labels.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->visible(fn () => filled($this->record->attachment_path))
            ->action(function () {
                $path = $this->record->attachment_path;
                $disk = 'public';

                if (! Storage::disk($disk)->exists($path)) {
                    Notification::make()
                        ->title(__('messages.file_not_found'))
                        ->body(__('messages.file_not_found_disk'))
                        ->danger()
                        ->send();

                    return;
                }

                return Storage::disk($disk)->download($path);
            });
    }
}
