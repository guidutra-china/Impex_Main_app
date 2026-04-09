<?php

namespace App\Domain\Logistics\Enums;

enum PackingProgressStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case PARTIAL = 'partial';
    case COMPLETE = 'complete';
}
