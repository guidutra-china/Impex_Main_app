<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealTotals
{
    public function __construct(
        public int $cashBalance,
        public int $margin,
        public float $marginPct,
        // Total faturado ao cliente (mercadoria + frete cobrado + comissão separate),
        // em moeda de apresentação. A comissão embutida já está na mercadoria.
        public int $billedToClientPresentation,
        // Total recebido do cliente (mercadoria + reembolso de frete + comissão paga).
        public int $receivedTotalPresentation,
    ) {}
}
