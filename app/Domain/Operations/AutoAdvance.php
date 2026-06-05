<?php

namespace App\Domain\Operations;

use Illuminate\Database\Eloquent\Model;

/**
 * A declarative lifecycle automation: when a $sourceModelClass transitions to
 * $sourceToStatus, advance the model returned by ($resolveTarget) to
 * $targetStatus. Applied centrally by TransitionStatusAction (best-effort).
 */
final class AutoAdvance
{
    /**
     * @param  class-string<Model>  $sourceModelClass
     * @param  \Closure(Model): ?Model  $resolveTarget
     */
    public function __construct(
        public readonly string $sourceModelClass,
        public readonly string $sourceToStatus,
        public readonly \Closure $resolveTarget,
        public readonly string $targetStatus,
    ) {}
}
