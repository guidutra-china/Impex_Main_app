<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\DataTransferObjects\ResolvedCompany;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use InvalidArgumentException;

/**
 * Ponto único de resolução de empresa para todo fluxo que cria Company fora do
 * formulário do CRM (import por IA, registro em feira, sync offline). Antes de
 * criar, procura uma empresa existente por tax_number e depois por nome
 * normalizado — evitando registros duplicados quando o mesmo cliente/fornecedor
 * chega com variação de caixa ou espaçamento no nome.
 *
 * Ao reusar, preenche apenas campos em branco com os atributos recebidos (nunca
 * sobrescreve dados já cadastrados) e garante o papel informado.
 */
class ResolveOrCreateCompanyAction
{
    /**
     * @param  array<string, mixed>  $attributes  extra company fields: used on create,
     *                                            blank-fill only on reuse
     */
    public function execute(string $name, array $attributes = [], ?CompanyRole $ensureRole = null): ResolvedCompany
    {
        $normalized = Company::normalizeName($name);

        if ($normalized === null) {
            throw new InvalidArgumentException('Company name cannot be blank.');
        }

        $company = $this->findExisting($normalized, $attributes);

        if ($company !== null) {
            $this->fillBlankFields($company, $attributes);
            $this->ensureRole($company, $ensureRole);

            return new ResolvedCompany($company, wasCreated: false);
        }

        $company = Company::create(array_merge($attributes, [
            'name' => trim(preg_replace('/\s+/u', ' ', $name) ?? ''),
        ]));

        $this->ensureRole($company, $ensureRole);

        return new ResolvedCompany($company, wasCreated: true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findExisting(string $normalizedName, array $attributes): ?Company
    {
        $taxNumber = trim((string) ($attributes['tax_number'] ?? ''));

        if ($taxNumber !== '') {
            $byTaxNumber = Company::where('tax_number', $taxNumber)->first();

            if ($byTaxNumber !== null) {
                return $byTaxNumber;
            }
        }

        return Company::where('name_normalized', $normalizedName)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fillBlankFields(Company $company, array $attributes): void
    {
        foreach ($attributes as $field => $value) {
            if ($field === 'name' || blank($value)) {
                continue;
            }

            if (blank($company->{$field})) {
                $company->{$field} = $value;
            }
        }

        if ($company->isDirty()) {
            $company->save();
        }
    }

    private function ensureRole(Company $company, ?CompanyRole $role): void
    {
        if ($role === null) {
            return;
        }

        $company->companyRoles()->firstOrCreate(['role' => $role->value]);
    }
}
