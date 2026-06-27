<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\AI\Tools\Concerns\FormatsMoney;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of purchase orders to suppliers. Gated by `view-purchase-orders`.
 */
class SearchPurchaseOrdersTool implements AssistantTool
{
    use FormatsMoney;

    public function name(): string
    {
        return 'buscar_purchase_orders';
    }

    public function description(): string
    {
        return 'Busca purchase orders (POs) enviadas aos fornecedores. Use quando o usuário '
            .'perguntar sobre uma PO, seu fornecedor, status ou valor. '
            .'Status possíveis: draft, sent, confirmed, shipped, completed, cancelled. '
            .'Filtros opcionais; sem filtro retorna as 20 mais recentes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fornecedor' => ['type' => 'string', 'description' => 'Nome do fornecedor (empresa).'],
                'status' => ['type' => 'string', 'description' => 'Status da PO.'],
                'referencia' => ['type' => 'string', 'description' => 'Número/referência da PO.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-purchase-orders');
    }

    public function run(array $input, User $user): array
    {
        $orders = PurchaseOrder::query()
            ->with(['supplierCompany', 'items'])
            ->when(($input['fornecedor'] ?? '') !== '', fn ($q) => $q->whereHas(
                'supplierCompany',
                fn ($c) => $c->where('name', 'like', '%'.$input['fornecedor'].'%')
            ))
            ->when(($input['status'] ?? '') !== '', fn ($q) => $q->where('status', $input['status']))
            ->when(($input['referencia'] ?? '') !== '', fn ($q) => $q->where('reference', 'like', '%'.$input['referencia'].'%'))
            ->latest()
            ->limit(20)
            ->get();

        return [
            'count' => $orders->count(),
            'purchase_orders' => $orders->map(fn (PurchaseOrder $po) => [
                'reference' => $po->reference,
                'fornecedor' => $po->supplierCompany?->name,
                'status' => $po->status instanceof BackedEnum ? $po->status->value : $po->status,
                'total' => $this->formatMoney($po->total, $po->currency_code),
            ])->all(),
        ];
    }
}
