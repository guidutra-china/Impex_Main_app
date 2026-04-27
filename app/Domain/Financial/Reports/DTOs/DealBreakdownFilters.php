<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use Carbon\CarbonImmutable;

final readonly class DealBreakdownFilters
{
    /**
     * @param  list<ProformaInvoiceStatus>  $statuses
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $presentationCurrency,
        public array $statuses,
    ) {}

    /** @return list<string> */
    public function statusValues(): array
    {
        return array_map(fn (ProformaInvoiceStatus $s) => $s->value, $this->statuses);
    }

    public static function defaultStatuses(): array
    {
        return [
            ProformaInvoiceStatus::SENT,
            ProformaInvoiceStatus::CONFIRMED,
            ProformaInvoiceStatus::FINALIZED,
            ProformaInvoiceStatus::REOPENED,
        ];
    }
}
