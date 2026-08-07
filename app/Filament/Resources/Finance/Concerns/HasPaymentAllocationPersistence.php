<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AllocationCalculator;
use App\Domain\Infrastructure\Support\Money;
use Filament\Notifications\Notification;
use InvalidArgumentException;

trait HasPaymentAllocationPersistence
{
    protected array $pendingAllocations = [];

    protected array $pendingCreditApplications = [];

    protected function persistAllocations($payment, string $paymentCurrencyCode): void
    {
        foreach ($this->pendingAllocations as $allocationData) {
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
            $rateCapturedAt = $allocationData['exchange_rate_captured_at'] ?? null;

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
                    ->title(__('messages.allocation_conversion_incomplete', [], 'en')
                        ?: 'Incomplete allocation data')
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
                'exchange_rate_captured_at' => $resolved['exchange_rate'] !== null ? ($rateCapturedAt ?: now()->toDateString()) : null,
                'allocated_amount_in_document_currency' => $resolved['allocated_amount_in_document_currency'],
            ]);
        }
    }

    protected function persistCreditApplications($payment): void
    {
        foreach ($this->pendingCreditApplications as $creditData) {
            $creditItemId = $creditData['credit_schedule_item_id'] ?? null;
            $scheduleItemId = $creditData['payment_schedule_item_id'] ?? null;
            $creditAmount = (float) ($creditData['credit_amount'] ?? 0);

            if (! $creditItemId || ! $scheduleItemId || $creditAmount <= 0) {
                continue;
            }

            $creditItem = PaymentScheduleItem::find($creditItemId);
            $scheduleItem = PaymentScheduleItem::find($scheduleItemId);

            if (! $creditItem || ! $scheduleItem) {
                continue;
            }

            $creditMinor = Money::toMinor($creditAmount);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $scheduleItemId,
                'credit_schedule_item_id' => $creditItemId,
                'allocated_amount' => 0,
                'exchange_rate' => null,
                'allocated_amount_in_document_currency' => $creditMinor,
            ]);

            // Reserva o crédito (PAID = fora da lista de disponíveis) apenas
            // quando totalmente comprometido, contando também aplicações de
            // pagamentos ainda pendentes de aprovação — mesma matemática
            // consumido/restante/tolerância do ReconcileSettlementStateAction,
            // que reavalia com só os APPROVED quando o pagamento é decidido.
            // Aplicação parcial mantém PENDING: o saldo segue aplicável.
            $reserved = $creditItem->refresh()->credit_reserved_amount;

            if ($creditItem->amount - $reserved <= CreditNote::SETTLEMENT_TOLERANCE) {
                $creditItem->update([
                    'status' => PaymentScheduleStatus::PAID->value,
                ]);
            }
        }
    }
}
