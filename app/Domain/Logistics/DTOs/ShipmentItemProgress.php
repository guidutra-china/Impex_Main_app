<?php

namespace App\Domain\Logistics\DTOs;

use App\Domain\Logistics\Enums\PackingProgressStatus;

final readonly class ShipmentItemProgress
{
    /**
     * @param  array<int, array{label: string, placed: int, target: int, remaining: int}>  $parts
     *                                                                                           Non-empty only when the item has an active packing_split.
     *                                                                                           Each part has its own placed/target/remaining relative to the item's total quantity.
     */
    public function __construct(
        public int $shipmentItemId,
        public int $total,
        public int $packedComplete,
        public int $packedInProgress,
        public array $parts,
        public PackingProgressStatus $status,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->total - $this->packedComplete);
    }

    public function isSplit(): bool
    {
        return ! empty($this->parts);
    }

    public function remainingForPart(string $label): int
    {
        foreach ($this->parts as $part) {
            if ($part['label'] === $label) {
                return $part['remaining'];
            }
        }

        return 0;
    }
}
