<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class KpiSummary
{
    public function __construct(
        public int $totalReceived,
        public int $totalPaidSuppliers,
        public int $totalPaidShipments,
        public int $totalMargin,
        public int $dealCount,
        public int $totalCommissionReceived,
        public int $totalCommissionPaid,
        public int $totalBilled,
        public int $totalCashBalance,
        public int $totalOverallGain,
        public int $totalDebitNotes,
        public int $totalFreightMargin,
    ) {}
}
