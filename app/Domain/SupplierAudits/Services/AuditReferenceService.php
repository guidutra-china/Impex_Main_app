<?php

namespace App\Domain\SupplierAudits\Services;

use App\Domain\SupplierAudits\Models\SupplierAudit;

class AuditReferenceService
{
    public static function generate(): string
    {
        $year = now()->format('Y');
        $prefix = "AUD-{$year}-";

        $lastAudit = SupplierAudit::withTrashed()
            ->where('reference', 'like', $prefix . '%')
            ->orderByDesc('reference')
            ->first();

        if ($lastAudit) {
            $lastNumber = (int) str_replace($prefix, '', $lastAudit->reference);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
