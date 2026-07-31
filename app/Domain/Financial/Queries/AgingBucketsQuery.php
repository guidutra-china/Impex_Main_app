<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\OpenItemsSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Classic AR/AP aging over the open-item universe: not-yet-due, then overdue
 * split at 30/60 days. Items without a due date count as not-yet-due. Amounts
 * are remaining minor units grouped per currency, computed in PHP because
 * remaining_amount depends on allocations.
 */
final class AgingBucketsQuery
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd60_plus'];

    /** @return array<string, array<string, int>> bucket => currency => minor units */
    public static function receivables(): array
    {
        return self::buckets(app(OpenItemsSnapshot::class)->receivables());
    }

    /** @return array<string, array<string, int>> bucket => currency => minor units */
    public static function payables(): array
    {
        return self::buckets(app(OpenItemsSnapshot::class)->payables());
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return array<string, array<string, int>>
     */
    private static function buckets(Collection $items): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $result = array_fill_keys(self::BUCKETS, []);

        foreach ($items as $item) {
            /** @var PaymentScheduleItem $item */
            $remaining = $item->remaining_amount;

            if ($remaining <= 0) {
                continue;
            }

            $bucket = self::bucketFor($item, $today);
            $code = $item->currency_code;
            $result[$bucket][$code] = ($result[$bucket][$code] ?? 0) + $remaining;
        }

        return $result;
    }

    private static function bucketFor(PaymentScheduleItem $item, CarbonImmutable $today): string
    {
        if (! $item->isOverdueAsOf($today)) {
            return 'current';
        }

        $daysOverdue = $item->due_date->toImmutable()->startOfDay()->diffInDays($today);

        return match (true) {
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            default => 'd60_plus',
        };
    }
}
