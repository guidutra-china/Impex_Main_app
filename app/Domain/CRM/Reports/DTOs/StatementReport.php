<?php

namespace App\Domain\CRM\Reports\DTOs;

use App\Domain\CRM\Models\Company;
use Carbon\CarbonImmutable;

final class StatementReport
{
    /**
     * @param  list<StatementSection>  $sections
     */
    public function __construct(
        public readonly Company $company,
        public readonly CarbonImmutable $periodFrom,
        public readonly CarbonImmutable $periodTo,
        public readonly CarbonImmutable $generatedAt,
        public readonly string $locale,
        public readonly ?FinancialSummary $financialSummary,
        public readonly array $sections,
    ) {
    }

    /** @return list<StatementSection> */
    public function nonEmptySections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (StatementSection $s) => ! $s->isEmpty(),
        ));
    }

    public function hasAnyData(): bool
    {
        return $this->financialSummary !== null || $this->nonEmptySections() !== [];
    }
}
