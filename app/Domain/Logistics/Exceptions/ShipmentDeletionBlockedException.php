<?php

namespace App\Domain\Logistics\Exceptions;

class ShipmentDeletionBlockedException extends \RuntimeException
{
    /**
     * @param  string[]  $blockers  Human-readable business-rule blocker messages.
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode("\n", $blockers));
    }
}
