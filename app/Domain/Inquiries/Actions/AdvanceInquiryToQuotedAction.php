<?php

namespace App\Domain\Inquiries\Actions;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;

/**
 * When a Proforma Invoice is created for an inquiry, walk the inquiry forward to
 * QUOTED. Best-effort and idempotent: only advances from RECEIVED/QUOTING, skips
 * if already QUOTED or past it (WON/LOST/CANCELLED), and never throws (a failed
 * advance must not break PI creation).
 */
class AdvanceInquiryToQuotedAction
{
    /** Ordered forward path toward QUOTED. */
    private const PATH = [
        InquiryStatus::RECEIVED->value => InquiryStatus::QUOTING->value,
        InquiryStatus::QUOTING->value => InquiryStatus::QUOTED->value,
    ];

    public function execute(?Inquiry $inquiry): void
    {
        if ($inquiry === null) {
            return;
        }

        try {
            while (isset(self::PATH[$inquiry->getCurrentStatus()])) {
                $next = self::PATH[$inquiry->getCurrentStatus()];

                if (! $inquiry->canTransitionTo($next)) {
                    break;
                }

                $inquiry->transitionTo($next, notes: 'Auto-advance: Proforma Invoice created for inquiry');
                $inquiry->refresh();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
