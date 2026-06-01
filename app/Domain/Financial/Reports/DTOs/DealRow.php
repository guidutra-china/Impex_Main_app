<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealRow
{
    /**
     * @param  list<PoRow>  $purchaseOrders
     * @param  list<ShipmentAttributionRow>  $shipments
     * @param  list<DebitNoteRow>  $debitNotes
     */
    public function __construct(
        public PiInfo $pi,
        public ReceiptsBlock $receipts,
        public array $purchaseOrders,
        public array $shipments,
        public DealTotals $totals,
        public array $debitNotes = [],
    ) {}
}
