<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class ShipmentAttributionRow
{
    /**
     * @param  list<AdditionalCostRow>  $additionalCosts
     *
     * Notas de semântica (após o fix de frete):
     * - totalCostOriginal / attributed* = custo REAL para a Impex (forwarder_amount
     *   quando há repasse a forwarder; senão custos billable_to=company). Não inclui
     *   a parcela cobrada do cliente (que é receita, não custo).
     * - clientCharge* = frete cobrado do cliente (receita, billable_to=client).
     * - paid* = saída de caixa para forwarders (PSIs [forwarder-payable]), atribuída.
     * - freightReceived* = entrada de caixa do cliente reembolsando frete (PSIs
     *   client-receivable da shipment), atribuída.
     */
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $clientReference,
        public ?string $forwarderName,
        public string $currencyOriginal,
        public int $totalCostOriginal,
        public float $attributionPct,
        public AttributionBasis $basis,
        public int $attributedOriginal,
        public ?int $attributedPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public string $detailUrl,
        public array $additionalCosts,
        public int $clientChargeOriginal,
        public ?int $clientChargePresentation,
        public int $attributedClientChargeOriginal,
        public ?int $attributedClientChargePresentation,
        public int $freightReceivedOriginal,
        public ?int $freightReceivedPresentation,
    ) {}
}
