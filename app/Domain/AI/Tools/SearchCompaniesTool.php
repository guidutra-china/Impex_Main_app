<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\CRM\Models\Company;
use App\Models\User;
use BackedEnum;

/**
 * Read-only lookup of companies (clients, suppliers, forwarders) by name.
 * Gated by the same `view-companies` permission as the CRM panel.
 */
class SearchCompaniesTool implements AssistantTool
{
    public function name(): string
    {
        return 'buscar_empresas';
    }

    public function description(): string
    {
        return 'Busca empresas cadastradas (clientes, fornecedores ou despachantes) pelo nome ou '
            .'razão social. Use quando o usuário perguntar sobre uma empresa específica, quiser o '
            .'status de um cliente, ou precisar localizar um fornecedor. Retorna no máximo 20 resultados.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => [
                    'type' => 'string',
                    'description' => 'Texto a buscar no nome ou razão social da empresa.',
                ],
            ],
            'required' => ['q'],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-companies');
    }

    public function run(array $input, User $user): array
    {
        $term = trim((string) ($input['q'] ?? ''));

        if ($term === '') {
            return ['count' => 0, 'companies' => [], 'note' => 'Termo de busca vazio.'];
        }

        $companies = Company::query()
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('legal_name', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return [
            'count' => $companies->count(),
            'companies' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'status' => $company->status instanceof BackedEnum
                    ? $company->status->value
                    : $company->status,
                'city' => $company->address_city,
                'country' => $company->address_country,
            ])->all(),
        ];
    }
}
