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
 * `default()` é a única fonte do comportamento histórico do sistema: tudo da
 * contraparte, com descrição visível. `fromCompany()` recorre a ele campo a
 * campo em vez de repetir os mesmos valores — o histórico fica escrito em um
 * único lugar. Nenhum documento existente muda enquanto ninguém trocar um
 * valor no cadastro da empresa ou no modal.
 *
 * NCM não é um destes campos: é a classificação fiscal do importador, sempre
 * vem da contraparte (pivot) e não faz parte desta preferência — de propósito,
 * para ninguém "corrigir" essa assimetria depois achando que é uma omissão.
 *
 * `code`, `name` e `description` são entradas de resolução (dizem ao
 * ProductIdentityResolver onde procurar); `showDescription` é uma entrada de
 * renderização (diz ao template se a linha de descrição aparece), não algo
 * que o resolver consulta.
 */
class NamingPreference
{
    /** Chaves aceitas por withOverrides(), compartilhadas com os formulários Filament que as produzem. */
    public const KEY_CODE = 'naming_code_source';

    public const KEY_NAME = 'naming_name_source';

    public const KEY_DESCRIPTION = 'naming_description_source';

    public const KEY_SHOW_DESCRIPTION = 'naming_show_description';

    public function __construct(
        public readonly DocumentNamingSource $code,
        public readonly DocumentNamingSource $name,
        public readonly DocumentNamingSource $description,
        public readonly bool $showDescription,
    ) {}

    public static function default(): self
    {
        return new self(
            code: DocumentNamingSource::COUNTERPARTY,
            name: DocumentNamingSource::COUNTERPARTY,
            description: DocumentNamingSource::COUNTERPARTY,
            showDescription: true,
        );
    }

    /**
     * Lê os padrões salvos na empresa, campo a campo.
     *
     * $fallback é a matriz de $company quando o documento é endereçado a uma
     * filial — mesma ordem de fallback que o ProductIdentityResolver usa para
     * o pivot (filial primeiro, depois matriz). Cada um dos quatro campos é
     * resolvido independentemente: o valor da própria empresa, senão o da
     * matriz, senão o histórico. Uma filial que só configurou o nome ainda
     * herda os outros três da matriz, em vez de herdar tudo ou nada.
     *
     * Não navega parentCompany() sozinho — isso seria uma consulta ao banco
     * escondida dentro de um value object que deve ficar inerte. Quem chama
     * já tem as duas empresas carregadas (os templates já as mantêm).
     */
    public static function fromCompany(?Company $company, ?Company $fallback = null): self
    {
        $default = self::default();

        return new self(
            code: self::field($company, $fallback, 'document_code_source', $default->code),
            name: self::field($company, $fallback, 'document_name_source', $default->name),
            description: self::field($company, $fallback, 'document_description_source', $default->description),
            showDescription: self::field($company, $fallback, 'document_show_description', $default->showDescription),
        );
    }

    private static function field(?Company $company, ?Company $fallback, string $column, mixed $default): mixed
    {
        return $company?->{$column} ?? $fallback?->{$column} ?? $default;
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
            code: $this->source($options, self::KEY_CODE, $this->code),
            name: $this->source($options, self::KEY_NAME, $this->name),
            description: $this->source($options, self::KEY_DESCRIPTION, $this->description),
            showDescription: $this->flag($options, self::KEY_SHOW_DESCRIPTION, $this->showDescription),
        );
    }

    /**
     * Chave ausente ou em branco mantém o valor atual. Uma string que não bate
     * com nenhum case do enum também mantém o valor atual em vez de estourar —
     * isso roda no meio da geração de um documento, e um 500 ali é pior do
     * que ignorar um valor inválido.
     *
     * Essa política de degradar em vez de estourar cobre só o que chega pelo
     * formulário. Um valor já corrompido no banco (fora dos cases do enum)
     * ainda estoura ValueError na leitura do atributo, antes de chegar aqui —
     * de propósito: dado inconsistente no banco é bug de integridade e deve
     * quebrar alto, não ser silenciosamente engolido.
     *
     * @param  array<string, mixed>  $options
     */
    private function source(array $options, string $key, DocumentNamingSource $current): DocumentNamingSource
    {
        if (! array_key_exists($key, $options) || blank($options[$key])) {
            return $current;
        }

        if ($options[$key] instanceof DocumentNamingSource) {
            return $options[$key];
        }

        return DocumentNamingSource::tryFrom((string) $options[$key]) ?? $current;
    }

    /**
     * Mesma guarda de presença-e-blank de source(), para o único campo
     * booleano. Extraído à parte porque o único defeito encontrado nesta
     * unidade foi justamente uma cópia manual desta guarda que esqueceu o
     * blank() — um quinto campo booleano não tem chance de repetir o erro se
     * passar por aqui.
     *
     * blank(false) === false, então um toggle genuinamente desmarcado (valor
     * false) ainda desliga a descrição normalmente; só ausência, null e ''
     * são ignorados antes de chegar no filter_var abaixo.
     *
     * Valida em vez de fazer (bool) cru: 'false', 'off', 'no' e '0' viram
     * false; 'true', '1' e 1 viram true; uma string que não é nenhum dos dois
     * (ex.: 'lixo') não é reconhecida e mantém o valor atual, em vez de virar
     * true por acidente como (bool) 'false' faria.
     *
     * @param  array<string, mixed>  $options
     */
    private function flag(array $options, string $key, bool $current): bool
    {
        if (! array_key_exists($key, $options) || blank($options[$key])) {
            return $current;
        }

        return filter_var($options[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $current;
    }
}
