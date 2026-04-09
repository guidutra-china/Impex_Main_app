<?php

namespace App\Domain\CRM\Reports\DTOs;

use Carbon\CarbonImmutable;

final class StatementFilters
{
    /**
     * @param  list<string>  $sectionKeys
     * @param  'active'|'closed'|'all'  $statusScope
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $statusScope,
        public readonly array $sectionKeys,
        public readonly ?string $currency,
        public readonly string $locale,
    ) {
    }

    public function includes(string $sectionKey): bool
    {
        return in_array($sectionKey, $this->sectionKeys, true);
    }
}
