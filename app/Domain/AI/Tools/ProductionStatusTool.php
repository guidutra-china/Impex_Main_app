<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Models\User;
use BackedEnum;

/**
 * Read-only production status by schedule/PI/PO reference. Gated by `view-production-schedules`.
 */
class ProductionStatusTool implements AssistantTool
{
    public function name(): string
    {
        return 'status_producao';
    }

    public function description(): string
    {
        return 'Consulta o status de produção (production schedules). Use quando o usuário '
            .'perguntar sobre o andamento de produção de uma PI, PO ou production schedule. '
            .'Retorna quantidade planejada vs realizada e o status. '
            .'Status possíveis: draft, pending_approval, approved, rejected, completed. '
            .'O parâmetro "referencia" casa a referência do schedule, da PI ou da PO.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'referencia' => ['type' => 'string', 'description' => 'Referência do production schedule, PI ou PO.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-production-schedules');
    }

    public function run(array $input, User $user): array
    {
        $ref = trim((string) ($input['referencia'] ?? ''));

        $schedules = ProductionSchedule::query()
            ->with(['proformaInvoice', 'purchaseOrder', 'supplierCompany', 'entries'])
            ->when($ref !== '', fn ($q) => $q->where(function ($w) use ($ref) {
                $w->where('reference', 'like', "%{$ref}%")
                    ->orWhereHas('proformaInvoice', fn ($p) => $p->where('reference', 'like', "%{$ref}%"))
                    ->orWhereHas('purchaseOrder', fn ($p) => $p->where('reference', 'like', "%{$ref}%"));
            }))
            ->latest()
            ->limit(20)
            ->get();

        return [
            'count' => $schedules->count(),
            'production_schedules' => $schedules->map(fn (ProductionSchedule $schedule) => [
                'reference' => $schedule->reference,
                'pi' => $schedule->proformaInvoice?->reference,
                'po' => $schedule->purchaseOrder?->reference,
                'fornecedor' => $schedule->supplierCompany?->name,
                'status' => $schedule->status instanceof BackedEnum ? $schedule->status->value : $schedule->status,
                'qtd_planejada' => $schedule->total_quantity,
                'qtd_real' => $schedule->total_actual_quantity,
            ])->all(),
        ];
    }
}
