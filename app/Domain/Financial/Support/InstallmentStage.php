<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

/**
 * O que faz duas parcelas serem "iguais" para fins de soma: mesmo estágio,
 * mesmo lado e mesma moeda. Fonte única — usada pela aba de pagamentos do
 * embarque (grupo do Filament) e pelo portal do cliente (InstallmentStageGrouper).
 *
 * A chave é feita só de colunas reais, para poder ser traduzida em SQL:
 *
 *   client|30.00|before_shipment|USD     parcela do cliente (espelho/PI)
 *   supplier|30.00|before_shipment|USD   parcela do fornecedor (PO)
 *   cost_in|USD / cost_out|USD           custos adicionais a receber/pagar
 *   credit|USD                           créditos
 *
 * Lado e moeda entram na chave: o mesmo "30% — Before Shipment" existe para
 * o cliente e para a fábrica, e moedas diferentes não se somam.
 */
final class InstallmentStage
{
    public static function key(PaymentScheduleItem $item): string
    {
        $currency = (string) $item->currency_code;
        $bucket = self::bucket($item);

        if (! self::isStage($bucket)) {
            return "{$bucket}|{$currency}";
        }

        return implode('|', [
            $bucket,
            number_format((float) $item->percentage, 2, '.', ''),
            (string) $item->getRawOriginal('due_condition'),
            $currency,
        ]);
    }

    public static function bucket(PaymentScheduleItem $item): string
    {
        if ($item->is_credit) {
            return 'credit';
        }

        if ($item->source_type !== null || $item->getRawOriginal('due_condition') === null) {
            $notes = (string) $item->notes;
            $isPayable = str_contains($notes, PaymentScheduleItem::FORWARDER_PAYABLE_TAG)
                || str_contains($notes, PaymentScheduleItem::SUPPLIER_PAYABLE_TAG);

            return $isPayable ? 'cost_out' : 'cost_in';
        }

        return $item->payable_type === PurchaseOrder::class ? 'supplier' : 'client';
    }

    /** Buckets que representam um estágio do cronograma (têm % e due_condition). */
    public static function isStage(string $bucket): bool
    {
        return in_array($bucket, ['client', 'supplier'], true);
    }

    /** Rótulo sem o sufixo "— [SH-… / PI-…]", que é o que diferencia as parcelas iguais. */
    public static function cleanLabel(PaymentScheduleItem $item): string
    {
        return trim((string) preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', (string) $item->label));
    }
}
