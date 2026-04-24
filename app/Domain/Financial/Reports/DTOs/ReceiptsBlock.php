<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class ReceiptsBlock
{
    /** @param  list<ReceiptItem>  $items */
    public function __construct(
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public float $percentPaid,
        public array $items,
    ) {
    }
}
