<?php

namespace App\Domain\CRM\Reports\DTOs;

use Carbon\CarbonImmutable;

final class FinancialReportFilters
{
    /**
     * @param  list<string>  $sectionKeys
     * @param  'active'|'closed'|'all'  $statusScope
     * @param  'admin'|'client'|'supplier'  $context
     * @param  array<string,list<int>>  $excluded  Entity IDs to omit, keyed by section key
     *                                             (e.g. ['proforma_invoices' => [1,5], 'shipments' => [2]])
     * @param  bool  $showDetails  When false, section builders skip payment-schedule / allocation detail rows
     *                             and emit only the summary (header) row per entity.
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $statusScope,
        public readonly array $sectionKeys,
        public readonly ?string $currency,
        public readonly string $locale,
        public readonly string $context,
        public readonly array $excluded = [],
        public readonly bool $showDetails = true,
    ) {}

    public function includes(string $sectionKey): bool
    {
        return in_array($sectionKey, $this->sectionKeys, true);
    }

    public function isExcluded(string $sectionKey, int $id): bool
    {
        return in_array($id, $this->excluded[$sectionKey] ?? [], true);
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
