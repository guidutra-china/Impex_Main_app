<?php

namespace App\Domain\Financial\Traits;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Services\ScheduleExpectationCalculator;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPaymentSchedule
{
    /**
     * Per-request memo for the staleness check (it hits the DB).
     *
     * @var array{state: string, actual: int, expected: int, diff: int}|null
     */
    protected ?array $scheduleStalenessMemo = null;

    public function paymentScheduleItems(): MorphMany
    {
        return $this->morphMany(PaymentScheduleItem::class, 'payable')->orderBy('sort_order');
    }

    public function hasPaymentSchedule(): bool
    {
        return $this->paymentScheduleItems()->exists();
    }

    public function getScheduleTotalAttribute(): int
    {
        return $this->paymentScheduleItems->sum('amount');
    }

    public function getSchedulePaidTotalAttribute(): int
    {
        $scheduleItemIds = $this->paymentScheduleItems()->pluck('id');

        if ($scheduleItemIds->isEmpty()) {
            return 0;
        }

        return (int) PaymentAllocation::whereIn('payment_schedule_item_id', $scheduleItemIds)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');
    }

    /**
     * Totais de documento carregam 4 casas (custo × quantidade); pagamentos
     * chegam com 2. A diferença é invisível na tela mas derruba um `>=`
     * seco (PO-2026-00054: 33.267,8744 devidos contra 33.267,8700 pagos).
     * Mesma tolerância que faz a parcela virar PAID
     * ({@see \App\Domain\Financial\Models\PaymentScheduleItem::getIsPaidInFullAttribute()}).
     */
    public const PAID_ROUNDING_TOLERANCE = 100;

    /** O pago cobre $amount, absorvendo arredondamento? */
    public function schedulePaidCovers(int $amount): bool
    {
        return $amount > 0 && $this->schedule_paid_total >= $amount - self::PAID_ROUNDING_TOLERANCE;
    }

    /** O pago é, na prática, exatamente $amount? */
    public function schedulePaidMatches(int $amount): bool
    {
        return $amount > 0 && abs($this->schedule_paid_total - $amount) <= self::PAID_ROUNDING_TOLERANCE;
    }

    public function getScheduleRemainingAttribute(): int
    {
        return max(0, $this->schedule_total - $this->schedule_paid_total);
    }

    public function getPaymentProgressAttribute(): float
    {
        if ($this->schedule_total <= 0) {
            return 0;
        }

        return round(($this->schedule_paid_total / $this->schedule_total) * 100, 1);
    }

    /**
     * Summarized payment state for badge display.
     *
     * @return 'paid'|'partial'|'pending'|'none'
     */
    public function getPaymentStateAttribute(): string
    {
        if ($this->schedule_total <= 0) {
            return 'none';
        }

        if ($this->schedule_remaining <= 0) {
            return 'paid';
        }

        return $this->schedule_paid_total > 0 ? 'partial' : 'pending';
    }

    /**
     * Soma dos itens base do schedule (originados de stages do payment term).
     * Exclui itens de AdditionalCost (source_type) e créditos. Itens WAIVED
     * entram na soma porque a regeneração mantém o amount deles correto —
     * excluí-los quebraria a comparação com o total esperado.
     */
    public function getScheduleBaseTotal(): int
    {
        return (int) $this->paymentScheduleItems()
            ->whereNotNull('payment_term_stage_id')
            ->whereNull('source_type')
            ->where('is_credit', false)
            ->sum('amount');
    }

    /**
     * O schedule base ainda reflete os valores atuais do documento?
     *
     * States:
     *  - ok         → em sincronia (ou nada a comparar)
     *  - stale      → valores divergem; "Regenerate" corrige
     *  - overridden → há itens com override manual; divergência intencional
     *  - plan       → há itens de Shipment Plan ainda não vinculados a
     *                 shipment; a partição é gerida pelo fluxo de planos e um
     *                 regenerate apagaria os itens do plano — verificar manualmente
     *
     * @return array{state: string, actual: int, expected: int, diff: int}
     */
    public function scheduleStaleness(): array
    {
        if ($this->scheduleStalenessMemo !== null) {
            return $this->scheduleStalenessMemo;
        }

        return $this->scheduleStalenessMemo = $this->computeScheduleStaleness();
    }

    public function isScheduleStale(): bool
    {
        return $this->scheduleStaleness()['state'] === 'stale';
    }

    public function getScheduleStaleDiff(): int
    {
        return $this->scheduleStaleness()['diff'];
    }

    /**
     * @return array{state: string, actual: int, expected: int, diff: int}
     */
    protected function computeScheduleStaleness(): array
    {
        $ok = fn (int $actual = 0, int $expected = 0, string $state = 'ok') => [
            'state' => $state,
            'actual' => $actual,
            'expected' => $expected,
            'diff' => $actual - $expected,
        ];

        $baseItems = $this->paymentScheduleItems()
            ->whereNotNull('payment_term_stage_id')
            ->whereNull('source_type')
            ->where('is_credit', false)
            ->get(['id', 'amount', 'payment_term_stage_id', 'shipment_id', 'shipment_plan_id', 'overridden_at']);

        if ($baseItems->isEmpty()) {
            return $ok();
        }

        // Override manual de valor = divergência intencional; não alarmar.
        if ($baseItems->whereNotNull('overridden_at')->isNotEmpty()) {
            return $ok(state: 'overridden');
        }

        $calculator = app(ScheduleExpectationCalculator::class);
        $expected = $calculator->expectedBaseTotal($this);

        if ($expected === null) {
            return $ok();
        }

        // Itens de plano sem shipment vinculado: a fatia [remaining] pertence ao
        // fluxo de Shipment Plans (Confirm/Reconcile), não ao regenerate.
        if ($baseItems->first(fn ($i) => $i->shipment_plan_id !== null && $i->shipment_id === null)) {
            return $ok(state: 'plan');
        }

        $actual = (int) $baseItems->sum('amount');

        // Troca de payment term / edição de stages: itens apontando para
        // stages que não existem mais no term atual.
        $currentStageIds = $calculator->currentStageIds($this);

        if ($currentStageIds !== null) {
            $unknownStage = $baseItems
                ->pluck('payment_term_stage_id')
                ->diff($currentStageIds)
                ->isNotEmpty();

            if ($unknownStage) {
                return [
                    'state' => 'stale',
                    'actual' => $actual,
                    'expected' => $expected,
                    'diff' => $actual - $expected,
                ];
            }
        }

        // Cada stage pode estar particionado em N embarques + [remaining],
        // cada parcela arredondada individualmente — 1 unidade monetária por
        // item absorve o acúmulo de arredondamento sem falso positivo.
        $tolerance = 100 * max(1, $baseItems->count());

        if (abs($actual - $expected) <= $tolerance) {
            return $ok($actual, $expected);
        }

        return [
            'state' => 'stale',
            'actual' => $actual,
            'expected' => $expected,
            'diff' => $actual - $expected,
        ];
    }

    public function hasUnresolvedBlockingPayments(string $targetStatus): bool
    {
        $blockers = PaymentScheduleItem::blockingConditionsForTransition($this, $targetStatus);

        return count($blockers) > 0;
    }

    public function getBlockingPaymentLabels(string $targetStatus): array
    {
        $blockers = PaymentScheduleItem::blockingConditionsForTransition($this, $targetStatus);

        return array_map(fn (PaymentScheduleItem $item) => $item->label, $blockers);
    }
}
