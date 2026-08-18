<?php

namespace App\Domain\Catalog\DataTransferObjects;

/**
 * Como uma contraparte (cliente ou fornecedor) identifica um produto num
 * documento: código, nome e descrição já resolvidos pela regra do negócio
 * (código da contraparte > model number > SKU interno).
 *
 * Não carrega placeholder: `code` vazio é string vazia e `description` ausente
 * é null — quem renderiza decide se mostra "—" ou célula vazia, porque essa
 * convenção difere entre os documentos.
 *
 * @see \App\Domain\Catalog\Services\ProductIdentityResolver
 */
class ProductIdentity
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
        /** NCM do importador — só existe no lado cliente. */
        public readonly ?string $ncm = null,
        /** True quando o código veio do pivot da contraparte, não do produto. */
        public readonly bool $fromCounterparty = false,
    ) {}

    public function codeOr(string $placeholder = '—'): string
    {
        return $this->code !== '' ? $this->code : $placeholder;
    }

    public static function empty(string $placeholderName = '—'): self
    {
        return new self(code: '', name: $placeholderName);
    }
}
