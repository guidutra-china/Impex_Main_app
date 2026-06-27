<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\Logistics\Models\Shipment;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of shipments. Gated by `view-shipments`.
 */
class SearchShipmentsTool implements AssistantTool
{
    public function name(): string
    {
        return 'buscar_shipments';
    }

    public function description(): string
    {
        return 'Busca embarques (shipments). Use quando o usuário perguntar sobre um embarque, '
            .'seu status, datas (ETD/ETA) ou as PIs/POs associadas. '
            .'Status possíveis: draft, booked, customs, arrived, cancelled. '
            .'Filtros opcionais; sem filtro retorna os 20 mais recentes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Status do embarque.'],
                'referencia' => ['type' => 'string', 'description' => 'Número/referência do embarque.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-shipments');
    }

    public function run(array $input, User $user): array
    {
        $shipments = Shipment::query()
            ->with(['company', 'items.proformaInvoiceItem.proformaInvoice', 'items.purchaseOrderItem.purchaseOrder'])
            ->when(($input['status'] ?? '') !== '', fn ($q) => $q->where('status', $input['status']))
            ->when(($input['referencia'] ?? '') !== '', fn ($q) => $q->where('reference', 'like', '%'.$input['referencia'].'%'))
            ->latest()
            ->limit(20)
            ->get();

        return [
            'count' => $shipments->count(),
            'shipments' => $shipments->map(fn (Shipment $shipment) => [
                'reference' => $shipment->reference,
                'status' => $shipment->status instanceof BackedEnum ? $shipment->status->value : $shipment->status,
                'parte' => $shipment->company?->name,
                'etd' => $shipment->etd?->toDateString(),
                'eta' => $shipment->eta?->toDateString(),
                'pis' => $shipment->proforma_invoice_references,
                'pos' => $shipment->purchase_order_references,
            ])->all(),
        ];
    }
}
