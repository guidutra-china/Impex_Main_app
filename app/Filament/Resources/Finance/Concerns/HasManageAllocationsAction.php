<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AllocationCalculator;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Shared "Manage Allocations" modal action for View pages of approved
 * payments (both Accounts Receivable and Accounts Payable).
 *
 * Builds a dual-currency allocation form identical to the one used on
 * Create/Edit, and persists each row through {@see AllocationCalculator::resolve()}
 * so that cross-currency amounts are never silently corrupted.
 */
trait HasManageAllocationsAction
{
    use HasPaymentFormSections;

    protected function manageAllocationsAction(): Action
    {
        return Action::make('manageAllocations')
            ->label(__('forms.labels.manage_allocations'))
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn () => $this->record->status === \App\Domain\Financial\Enums\PaymentStatus::APPROVED
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
                Hidden::make('currency_code')
                    ->default($this->record->currency_code),

                Repeater::make('new_allocations')
                    ->label('')
                    ->schema(static::allocationRepeaterSchema($this->record->direction))
                    ->columns(12)
                    ->defaultItems(1)
                    ->live()
                    ->addActionLabel('+ '.__('forms.labels.add_allocation')),

                Placeholder::make('modal_allocation_summary')
                    ->label('')
                    ->content(function (Get $get) {
                        return new HtmlString(static::buildAllocationSummary(
                            $get('new_allocations') ?? [],
                            Money::toMajor($this->record->unallocated_amount),
                            $this->record->currency_code,
                        ));
                    }),
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

                    $pmtMajor = isset($allocationData['allocated_amount'])
                        ? (float) $allocationData['allocated_amount']
                        : null;
                    $docMajor = isset($allocationData['allocated_amount_in_document_currency'])
                        ? (float) $allocationData['allocated_amount_in_document_currency']
                        : null;
                    $rate = isset($allocationData['exchange_rate']) && $allocationData['exchange_rate'] !== ''
                        ? (float) $allocationData['exchange_rate']
                        : null;

                    if (($pmtMajor === null || $pmtMajor <= 0)
                        && ($docMajor === null || $docMajor <= 0)) {
                        continue;
                    }

                    try {
                        $resolved = AllocationCalculator::resolve(
                            $paymentCurrencyCode,
                            $scheduleItem->currency_code,
                            $pmtMajor,
                            $docMajor,
                            $rate,
                        );
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Incomplete allocation data')
                            ->body(
                                $e->getMessage().' '
                                ."[{$paymentCurrencyCode} → {$scheduleItem->currency_code}]"
                            )
                            ->danger()
                            ->persistent()
                            ->send();

                        $this->halt();

                        return;
                    }

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'payment_schedule_item_id' => $scheduleItemId,
                        'allocated_amount' => $resolved['allocated_amount'],
                        'exchange_rate' => $resolved['exchange_rate'],
                        'allocated_amount_in_document_currency' => $resolved['allocated_amount_in_document_currency'],
                    ]);

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
}
