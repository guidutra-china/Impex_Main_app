<?php

namespace App\Domain\Catalog\DataTransferObjects;

use App\Domain\Catalog\Support\Ncm;

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
        /**
         * NCM do importador — só existe no lado cliente. Guarda os 8
         * dígitos crus do pivot, sem formatação nem validação: quem quer o
         * NCM pronto para imprimir usa {@see self::ncmHeading()}, não este
         * campo diretamente.
         */
        public readonly ?string $ncm = null,
        /** True quando o código veio do pivot da contraparte, não do produto. */
        public readonly bool $fromCounterparty = false,
    ) {}

    public function codeOr(string $placeholder = '—'): string
    {
        return $this->code !== '' ? $this->code : $placeholder;
    }

    /**
     * NCM pronto para imprimir: a posição de 4 dígitos, ou null quando o
     * valor cru não é claramente um NCM. Regra e motivo em
     * {@see \App\Domain\Catalog\Support\Ncm::heading()} — é lá que o próximo
     * documento com coluna de NCM (Packing List, por exemplo) deve ir
     * buscar a mesma formatação, em vez de reimplementar a extração de
     * dígitos por conta própria.
     */
    public function ncmHeading(): ?string
    {
        return Ncm::heading($this->ncm);
    }

    public static function empty(string $placeholderName = '—'): self
    {
        return new self(code: '', name: $placeholderName);
    }
}
