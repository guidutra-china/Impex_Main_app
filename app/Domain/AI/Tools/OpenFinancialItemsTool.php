<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\AI\Tools\Concerns\FormatsMoney;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Models\User;
use BackedEnum;

/**
 * Read-only open financial items (AR/AP). Gated by `view-payments` — the same
 * permission as the AccountsReceivable/Payable open-item pages that surface this
 * exact dataset. Wraps the canonical OpenScheduleItemsQuery used by those worklists.
 */
class OpenFinancialItemsTool implements AssistantTool
{
    use FormatsMoney;

    public function name(): string
    {
        return 'financeiro_em_aberto';
    }

    public function description(): string
    {
        return 'Lista títulos financeiros em aberto. Use quando o usuário perguntar sobre contas '
            .'a receber (de clientes) ou a pagar (a fornecedores), valores em aberto ou vencimentos. '
            .'O parâmetro "tipo" é obrigatório: "receber" (recebíveis) ou "pagar" (pagáveis). '
            .'Retorna totais por moeda, contagem de títulos e vencidos, e os 20 mais próximos do vencimento.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => [
                    'type' => 'string',
                    'enum' => ['receber', 'pagar'],
                    'description' => 'receber = recebíveis (clientes); pagar = pagáveis (fornecedores).',
                ],
            ],
            'required' => ['tipo'],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-payments');
    }

    public function run(array $input, User $user): array
    {
        $tipo = ($input['tipo'] ?? '') === 'pagar' ? 'pagar' : 'receber';

        $builder = $tipo === 'pagar'
            ? OpenScheduleItemsQuery::payables()
            : OpenScheduleItemsQuery::receivables();

        $items = $builder->orderBy('due_date')->get();

        $totals = [];
        foreach ($items as $item) {
            $currency = $item->currency_code ?: '—';
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $item->remaining_amount;
        }

        $totalsFormatted = [];
        foreach ($totals as $currency => $sum) {
            $totalsFormatted[$currency] = $this->formatMoney($sum, $currency === '—' ? null : $currency);
        }

        $overdue = $items->filter(
            fn (PaymentScheduleItem $item) => ($item->status instanceof BackedEnum ? $item->status->value : $item->status)
                === PaymentScheduleStatus::OVERDUE->value
        )->count();

        return [
            'tipo' => $tipo,
            'titulos_em_aberto' => $items->count(),
            'vencidos' => $overdue,
            'totais_por_moeda' => $totalsFormatted,
            'itens' => $items->take(20)->map(fn (PaymentScheduleItem $item) => [
                'titulo' => $item->label,
                'valor' => $this->formatMoney((int) $item->amount, $item->currency_code),
                'em_aberto' => $this->formatMoney((int) $item->remaining_amount, $item->currency_code),
                'vencimento' => $item->due_date?->toDateString(),
                'status' => $item->status instanceof BackedEnum ? $item->status->value : $item->status,
            ])->values()->all(),
        ];
    }
}
