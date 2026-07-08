<?php

namespace App\Domain\CRM\Reports;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

/**
 * Saldo em aberto de um documento para fins de relatório financeiro:
 * documento cancelado não deve dinheiro (saldo 0) e parcelas WAIVED são
 * dívida perdoada (saem do saldo). O que já foi pago continua reportado
 * como pago — só o "em aberto" é zerado.
 */
final class DocumentBalance
{
    public static function open(ProformaInvoice|PurchaseOrder $doc, int $invoicedMinor, int $paidMinor): int
    {
        if (self::isCancelled($doc)) {
            return 0;
        }

        return max(0, $invoicedMinor - $paidMinor - self::waivedOpen($doc));
    }

    public static function isCancelled(ProformaInvoice|PurchaseOrder $doc): bool
    {
        $status = $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status;

        return $status === 'cancelled';
    }

    /** Valor ainda em aberto das parcelas WAIVED (não-crédito) do documento. */
    public static function waivedOpen(ProformaInvoice|PurchaseOrder $doc): int
    {
        return (int) $doc->paymentScheduleItems
            ->where('is_credit', false)
            ->where('status', PaymentScheduleStatus::WAIVED)
            ->sum(fn ($item) => max(0, (int) $item->amount - (int) $item->paid_amount));
    }
}
