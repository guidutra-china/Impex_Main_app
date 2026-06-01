<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\Financial\Enums\DebitNoteStatus;

final readonly class DebitNoteRow
{
    public function __construct(
        public int $id,
        public string $reference,
        public ?\Carbon\CarbonImmutable $issuedAt,
        public string $currencyOriginal,
        public int $totalOriginal,
        public ?int $totalPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public DebitNoteStatus $status,
        public string $detailUrl,
    ) {}
}
