<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\AI\Tools\Concerns\FormatsMoney;
use App\Domain\Quotations\Models\Quotation;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of quotations sent to clients. Gated by `view-quotations`.
 */
class SearchQuotationsTool implements AssistantTool
{
    use FormatsMoney;

    public function name(): string
    {
        return 'buscar_quotations';
    }

    public function description(): string
    {
        return 'Busca cotações enviadas aos clientes. Use quando o usuário perguntar sobre '
            .'cotações de um cliente, valores cotados ou status de uma quotation. '
            .'Status possíveis: draft, sent, negotiating, approved, rejected, expired, cancelled. '
            .'Filtros opcionais; sem filtro retorna as 20 mais recentes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente' => ['type' => 'string', 'description' => 'Nome do cliente (empresa).'],
                'status' => ['type' => 'string', 'description' => 'Status da cotação.'],
                'referencia' => ['type' => 'string', 'description' => 'Número/referência da cotação.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-quotations');
    }

    public function run(array $input, User $user): array
    {
        $quotations = Quotation::query()
            ->with(['company', 'items'])
            ->when(($input['cliente'] ?? '') !== '', fn ($q) => $q->whereHas(
                'company',
                fn ($c) => $c->where('name', 'like', '%'.$input['cliente'].'%')
            ))
            ->when(($input['status'] ?? '') !== '', fn ($q) => $q->where('status', $input['status']))
            ->when(($input['referencia'] ?? '') !== '', fn ($q) => $q->where('reference', 'like', '%'.$input['referencia'].'%'))
            ->latest()
            ->limit(20)
            ->get();

        return [
            'count' => $quotations->count(),
            'quotations' => $quotations->map(fn (Quotation $quotation) => [
                'reference' => $quotation->reference,
                'cliente' => $quotation->company?->name,
                'status' => $quotation->status instanceof BackedEnum ? $quotation->status->value : $quotation->status,
                'total' => $this->formatMoney($quotation->total, $quotation->currency_code),
                'validade' => $quotation->valid_until?->toDateString(),
            ])->all(),
        ];
    }
}
