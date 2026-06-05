<?php

namespace App\Domain\Infrastructure\Exceptions;

class TransitionBlockedException extends \RuntimeException
{
    /**
     * @param  string[]  $blockers  Human-readable business-rule blocker messages.
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode("\n", $blockers));
    }
}
