<?php

namespace App\Domain\Quotations\Exceptions;

use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use RuntimeException;

class QuotationLockedException extends RuntimeException
{
    public function __construct(
        public readonly Quotation $quotation,
    ) {
        parent::__construct(sprintf(
            'Quotation %s is in status %s and cannot be recomputed without forceNewVersion.',
            $quotation->reference,
            $quotation->status instanceof QuotationStatus ? $quotation->status->value : (string) $quotation->status,
        ));
    }
}
