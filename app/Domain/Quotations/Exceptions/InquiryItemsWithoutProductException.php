<?php

namespace App\Domain\Quotations\Exceptions;

use App\Domain\Inquiries\Models\Inquiry;
use RuntimeException;

/**
 * A Inquiry tem linhas sem produto vinculado: a Quotation é montada por produto
 * (quotation_items.product_id é obrigatório), então não dá para cotá-las. A UI
 * mostra `descriptions` ao trader para ele vincular os produtos e tentar de novo.
 */
class InquiryItemsWithoutProductException extends RuntimeException
{
    /**
     * @param  list<string>  $descriptions
     */
    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly array $descriptions,
    ) {
        parent::__construct(sprintf(
            'Inquiry %s has %d item(s) without a linked product: %s',
            $inquiry->reference,
            count($descriptions),
            implode('; ', $descriptions),
        ));
    }
}
