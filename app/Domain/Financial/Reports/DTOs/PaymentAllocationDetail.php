<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\Financial\Enums\PaymentStatus;
use Carbon\CarbonImmutable;

final class PaymentAllocationDetail
{
    public function __construct(
        public readonly int $paymentId,
        public readonly string $paymentReference,
        public readonly CarbonImmutable $paymentDate,
        public readonly PaymentStatus $paymentStatus,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $currency,
        public readonly int $paymentAmount,
        public readonly int $allocatedAmount,
        public readonly int $unallocatedAmount,
        /** @var list<array{label: string, document_type: ?string, document_reference: ?string, document_url: ?string, client_reference: ?string, amount: int, is_credit: bool}> */
        public readonly array $allocations,
        public readonly ?string $detailUrl,
    ) {}
}
