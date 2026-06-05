<?php

namespace App\Domain\Operations;

use Illuminate\Database\Eloquent\Builder;

/**
 * One stage of the end-to-end Operations pipeline. Declares which model and
 * which statuses constitute the stage; query() returns the base query so
 * consumers (Kanban, future cross-feature locks) share one definition of
 * stage membership.
 */
final class PipelineStage
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<\BackedEnum>  $statuses
     * @param  array<string>  $eagerLoad
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $color,
        public readonly string $modelClass,
        public readonly array $statuses,
        public readonly array $eagerLoad = [],
    ) {}

    public function query(): Builder
    {
        return ($this->modelClass)::query()
            ->with($this->eagerLoad)
            ->whereIn('status', $this->statuses);
    }
}
