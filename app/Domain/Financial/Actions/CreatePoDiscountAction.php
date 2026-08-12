<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;

/**
 * Atalho da tela da PO: lança um Desconto do fornecedor no processo. O custo
 * vive na PI (âncora de processo, como todos os custos adicionais); o crédito
 * resultante ancora NESTA PO — via casamento por fornecedor no
 * resolvePayableForCost — e é abatido ao pagar o fornecedor.
 */
class CreatePoDiscountAction
{
    public function execute(PurchaseOrder $po, int $amountMinor, string $description, ?float $percent = null): AdditionalCost
    {
        $pi = $po->proformaInvoice;

        if (! $pi) {
            throw new \InvalidArgumentException('Purchase order has no linked proforma invoice.');
        }

        $currencyCode = $po->currency_code ?? $pi->currency_code;
        $documentCurrency = $pi->currency_code;

        $exchangeRate = null;
        $amountInDoc = $amountMinor;

        if ($currencyCode !== $documentCurrency) {
            $amountInDoc = $this->convert($currencyCode, $documentCurrency, $amountMinor);
            if ($amountInDoc !== $amountMinor && $amountMinor > 0) {
                $exchangeRate = $amountInDoc / $amountMinor;
            }
        }

        $cost = $pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::DISCOUNT->value,
            'description' => $description,
            'amount' => $amountMinor, // hook do model normaliza para negativo
            'currency_code' => $currencyCode,
            'exchange_rate' => $exchangeRate,
            'amount_in_document_currency' => $amountInDoc,
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $po->supplier_company_id,
            'status' => AdditionalCostStatus::PENDING->value,
            'notes' => $percent !== null ? "Desconto de {$percent}% sobre {$po->reference}" : null,
        ]);

        app(GeneratePaymentScheduleAction::class)->syncCostScheduleItems($pi);

        return $cost;
    }

    protected function convert(string $fromCode, string $toCode, int $amountMinor): int
    {
        $from = Currency::where('code', $fromCode)->first();
        $to = Currency::where('code', $toCode)->first();

        if ($from && $to) {
            $converted = ExchangeRate::convert($from->id, $to->id, Money::toMajor($amountMinor));

            if ($converted !== null) {
                return Money::toMinor($converted);
            }
        }

        return $amountMinor;
    }
}
