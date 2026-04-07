<?php

namespace App\Domain\CRM\DataTransferObjects;

use Carbon\CarbonInterface;

/**
 * A single chronological event on the Client 360 timeline.
 *
 * Events are anchor-based (entity creation, confirmation, payment, departure,
 * arrival) — not full state-machine history. Source events come from inquiries,
 * proforma invoices, purchase orders, shipments and payments.
 */
readonly class Client360TimelineEvent
{
    public function __construct(
        public CarbonInterface $occurredAt,
        /** Stable key used by the view to pick an icon and color (e.g. 'inquiry_created') */
        public string $type,
        public string $title,
        public ?string $subtitle,
        public ?string $url,
    ) {}
}
