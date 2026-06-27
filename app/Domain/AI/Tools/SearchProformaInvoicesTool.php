<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\AI\Tools\Concerns\FormatsMoney;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of proforma invoices. Gated by `view-proforma-invoices`.
 */
class SearchProformaInvoicesTool implements AssistantTool
{
    use FormatsMoney;

    public function name(): string
    {
        return 'buscar_proforma_invoices';
    }

    public function description(): string
    {
        return 'Busca proforma invoices (PIs). Use quando o usuário perguntar sobre uma PI, '
            .'seu status, valor total ou a parte (cliente) associada. '
            .'Status possíveis: draft, sent, confirmed, shipped, finalized, reopened, cancelled. '
            .'Filtros opcionais; sem filtro retorna as 20 mais recentes.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parte' => ['type' => 'string', 'description' => 'Nome da parte/cliente (empresa).'],
                'status' => ['type' => 'string', 'description' => 'Status da PI.'],
                'referencia' => ['type' => 'string', 'description' => 'Número/referência da PI.'],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-proforma-invoices');
    }

    public function run(array $input, User $user): array
    {
        $invoices = ProformaInvoice::query()
            ->with(['company', 'items', 'additionalCosts'])
            ->when(($input['parte'] ?? '') !== '', fn ($q) => $q->whereHas(
                'company',
                fn ($c) => $c->where('name', 'like', '%'.$input['parte'].'%')
            ))
            ->when(($input['status'] ?? '') !== '', fn ($q) => $q->where('status', $input['status']))
            ->when(($input['referencia'] ?? '') !== '', fn ($q) => $q->where('reference', 'like', '%'.$input['referencia'].'%'))
            ->latest()
            ->limit(20)
            ->get();

        return [
            'count' => $invoices->count(),
            'proforma_invoices' => $invoices->map(fn (ProformaInvoice $pi) => [
                'reference' => $pi->reference,
                'parte' => $pi->company?->name,
                'status' => $pi->status instanceof BackedEnum ? $pi->status->value : $pi->status,
                'total' => $this->formatMoney($pi->total, $pi->currency_code),
                'grand_total' => $this->formatMoney($pi->grand_total, $pi->currency_code),
            ])->all(),
        ];
    }
}
