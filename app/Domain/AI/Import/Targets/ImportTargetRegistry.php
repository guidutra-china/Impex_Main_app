<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Models\User;

/** All destinations the universal import knows about. */
class ImportTargetRegistry
{
    /** @var array<string,ImportTarget> */
    private array $targets = [];

    public function __construct()
    {
        foreach ([new SupplierQuotationTarget, new InquiryTarget] as $target) {
            $this->targets[$target->key()] = $target;
        }
    }

    /** @return array<string,ImportTarget> */
    public function all(): array
    {
        return $this->targets;
    }

    public function get(string $key): ?ImportTarget
    {
        return $this->targets[$key] ?? null;
    }

    /**
     * Targets this user may import into (chooser options + classifier enum).
     *
     * @return array<string,ImportTarget>
     */
    public function allFor(User $user): array
    {
        return array_filter($this->targets, fn (ImportTarget $t) => $t->authorize($user));
    }
}
