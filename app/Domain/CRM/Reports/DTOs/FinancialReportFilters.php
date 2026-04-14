<?php

namespace App\Domain\CRM\Reports\DTOs;

use Carbon\CarbonImmutable;

final class FinancialReportFilters
{
    /**
     * @param  list<string>  $sectionKeys
     * @param  'active'|'closed'|'all'  $statusScope
     * @param  'admin'|'client'|'supplier'  $context
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $statusScope,
        public readonly array $sectionKeys,
        public readonly ?string $currency,
        public readonly string $locale,
        public readonly string $context,
    ) {
    }

    public function includes(string $sectionKey): bool
    {
        return in_array($sectionKey, $this->sectionKeys, true);
    }

    public function isAdmin(): bool
    {
        return $this->context === 'admin';
    }

    public function isClient(): bool
    {
        return $this->context === 'client';
    }

    public function isSupplier(): bool
    {
        return $this->context === 'supplier';
    }
}
