<?php

namespace App\Domain\CRM\Reports;

final class StatusScopeFilter
{
    private const CLOSED_STATES = [
        'cancelled', 'rejected', 'expired', 'lost',
        'finalized', 'completed', 'arrived', 'won',
    ];

    private const ACTIVE_EXCLUDES = ['cancelled', 'rejected', 'expired', 'lost'];

    /** @return array{0:string,1:array<int,string>}|null  [operator, values] or null for no filter */
    public static function whereClause(string $scope): ?array
    {
        return match ($scope) {
            'active' => ['not in', self::ACTIVE_EXCLUDES],
            'closed' => ['in', self::CLOSED_STATES],
            default => null,
        };
    }
}
