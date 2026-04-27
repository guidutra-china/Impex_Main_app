<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use Carbon\CarbonImmutable;

final readonly class PiInfo
{
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $clientReference,
        public CarbonImmutable $issueDate,
        public ProformaInvoiceStatus $status,
        public string $currencyOriginal,
        public int $totalOriginal,
        public ?int $totalPresentation,
        public string $detailUrl,
    ) {}
}
