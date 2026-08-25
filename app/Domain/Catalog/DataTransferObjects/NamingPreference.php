<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DataTransferObjects;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;

/**
 * Como um documento deve nomear os produtos da contraparte.
 *
 * Value object em vez de quatro parâmetros soltos porque atravessa três camadas
 * (ação Filament -> template -> resolver) e engordaria todas as assinaturas.
 *
 * `default()` é o comportamento histórico do sistema: tudo da contraparte, com
 * descrição visível. Nenhum documento existente muda enquanto ninguém trocar um
 * valor no cadastro da empresa ou no modal.
 */
class NamingPreference
{
    public function __construct(
        public readonly DocumentNamingSource $code = DocumentNamingSource::Counterparty,
        public readonly DocumentNamingSource $name = DocumentNamingSource::Counterparty,
        public readonly DocumentNamingSource $description = DocumentNamingSource::Counterparty,
        public readonly bool $showDescription = true,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    public static function fromCompany(?Company $company): self
    {
        if (! $company) {
            return self::default();
        }

        return new self(
            code: $company->document_code_source ?? DocumentNamingSource::Counterparty,
            name: $company->document_name_source ?? DocumentNamingSource::Counterparty,
            description: $company->document_description_source ?? DocumentNamingSource::Counterparty,
            showDescription: $company->document_show_description ?? true,
        );
    }

    /**
     * Aplica o que veio do modal. Chave ausente mantém o valor da empresa — o
     * formulário só sobrepõe o que ele realmente controla.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOverrides(array $options): self
    {
        return new self(
            code: $this->source($options, 'naming_code_source', $this->code),
            name: $this->source($options, 'naming_name_source', $this->name),
            description: $this->source($options, 'naming_description_source', $this->description),
            showDescription: array_key_exists('show_description', $options)
                ? (bool) $options['show_description']
                : $this->showDescription,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function source(array $options, string $key, DocumentNamingSource $current): DocumentNamingSource
    {
        if (! array_key_exists($key, $options) || blank($options[$key])) {
            return $current;
        }

        return $options[$key] instanceof DocumentNamingSource
            ? $options[$key]
            : DocumentNamingSource::from((string) $options[$key]);
    }
}
