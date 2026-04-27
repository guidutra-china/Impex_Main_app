<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;

final readonly class PoRow
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $supplierName,
        public string $currencyOriginal,
        public int $totalOriginal,
        public ?int $totalPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public PurchaseOrderStatus $status,
        public string $detailUrl,
    ) {}
}
