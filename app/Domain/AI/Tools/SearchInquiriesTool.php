<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\Inquiries\Models\Inquiry;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of customer inquiries (RFQs). Gated by `view-inquiries`.
 */
class SearchInquiriesTool implements AssistantTool
{
    public function name(): string
    {
        return 'buscar_inquiries';
    }

    public function description(): string
    {
        return 'Busca pedidos de cotação (inquiries/RFQs) dos clientes. Use quando o usuário '
            .'perguntar sobre inquiries de um cliente ou o status de um pedido de cotação. '
            .'Status possíveis: received, quoting, quoted, won, lost, cancelled. '
            .'Filtros opcionais; sem filtro retorna os 20 mais recentes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente' => ['type' => 'string', 'description' => 'Nome do cliente (empresa).'],
                'status' => ['type' => 'string', 'description' => 'Status do inquiry.'],
                'referencia' => ['type' => 'string', 'description' => 'Número/referência do inquiry.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-inquiries');
    }

    public function run(array $input, User $user): array
    {
        $inquiries = Inquiry::query()
            ->with('company')
            ->withCount('items')
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
            'count' => $inquiries->count(),
            'inquiries' => $inquiries->map(fn (Inquiry $inquiry) => [
                'reference' => $inquiry->reference,
                'cliente' => $inquiry->company?->name,
                'status' => $inquiry->status instanceof BackedEnum ? $inquiry->status->value : $inquiry->status,
                'data' => $inquiry->created_at?->toDateString(),
                'itens' => $inquiry->items_count,
            ])->all(),
        ];
    }
}
