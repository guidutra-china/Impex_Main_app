<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class PaymentsListStats extends Widget
{
    protected string $view = 'portal.widgets.payments-list-stats';

    protected int|string|array $columnSpan = 'full';

    /** @var list<string> */
    private array $currencyOrder = [];

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $query = Payment::where('company_id', $tenant->id);

        $total = $query->count();
        $approved = (clone $query)->where('status', PaymentStatus::APPROVED)->count();
        $pending = (clone $query)->where('status', PaymentStatus::PENDING_APPROVAL)->count();

        // Valores por moeda: pagamentos em BRL e USD não se somam (a Deep tem
        // os dois — somar tudo como "USD" inflava o total com reais).
        $payments = (clone $query)
            ->withSum('allocations as allocated_sum', 'allocated_amount')
            ->get();
        $approvedPayments = $payments->where('status', PaymentStatus::APPROVED);

        // Mesma ordem de moedas em todos os cards (maior volume primeiro),
        // para as linhas ficarem alinhadas entre eles.
        $this->currencyOrder = $payments
            ->groupBy(fn ($p) => $p->currency_code ?? 'USD')
            ->map(fn ($group) => $group->sum('amount'))
            ->sortDesc()
            ->keys()
            ->all();

        $totalAmount = $this->byCurrency($payments, fn ($p) => (int) $p->amount);
        $approvedAmount = $this->byCurrency($approvedPayments, fn ($p) => (int) $p->amount);
        $allocatedAmount = $this->byCurrency($approvedPayments, fn ($p) => (int) $p->allocated_sum);
        $unallocatedAmount = $this->byCurrency($approvedPayments, fn ($p) => max(0, (int) $p->amount - (int) $p->allocated_sum));

        return [
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'totalAmount' => $totalAmount,
            'approvedAmount' => $approvedAmount,
            'allocatedAmount' => $allocatedAmount,
            'unallocatedAmount' => $unallocatedAmount,
            'hasUnallocated' => $approvedPayments->contains(fn ($p) => (int) $p->amount > (int) $p->allocated_sum),
        ];
    }

    /**
     * Uma linha "MOEDA valor" por moeda, na ordem de $currencyOrder; sem
     * pagamentos vira uma única linha zerada em USD.
     *
     * @return list<string>
     */
    private function byCurrency(Collection $payments, callable $value): array
    {
        $sums = $payments
            ->groupBy(fn ($p) => $p->currency_code ?? 'USD')
            ->map(fn ($group) => $group->sum($value));

        $lines = collect($this->currencyOrder)
            ->filter(fn ($currency) => $sums->has($currency))
            ->map(fn ($currency) => $currency.' '.Money::format($sums[$currency], 2))
            ->values()
            ->all();

        return $lines ?: ['USD '.Money::format(0, 2)];
    }
}
