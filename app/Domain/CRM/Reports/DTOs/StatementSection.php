<?php

namespace App\Domain\CRM\Reports\DTOs;

final class StatementSection
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string,scalar|null>>  $rows
     */
    public function __construct(
        public readonly string $key,
        public readonly string $titleKey,
        public readonly array $columns,
        public readonly array $rows,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
