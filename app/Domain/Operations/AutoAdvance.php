<?php

namespace App\Domain\Operations;

use Illuminate\Database\Eloquent\Model;

/**
 * A declarative lifecycle automation: when a $sourceModelClass transitions to
 * $sourceToStatus, advance the model(s) returned by ($resolveTargets) to
 * $targetStatus. Applied centrally by TransitionStatusAction (best-effort).
 */
final class AutoAdvance
{
    /**
     * @param  class-string<Model>  $sourceModelClass
     * @param  \Closure(Model): (Model|iterable<Model>|null)  $resolveTargets  Returns the target model(s) to advance; a single Model, an iterable of Models, or null.
     */
    public function __construct(
        public readonly string $sourceModelClass,
        public readonly string $sourceToStatus,
        public readonly \Closure $resolveTargets,
        public readonly string $targetStatus,
    ) {}
}
