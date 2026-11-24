<?php

namespace App\Domain\Financial\Reports;

use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Onde um pagamento foi alocado, uma linha por alocação, com as referências
 * que o cliente usa para se localizar: PI, referência dele, embarque e BL.
 *
 * Fonte única do modal do Portal e da planilha: os dois mostram exatamente as
 * mesmas linhas e os mesmos totais.
 *
 * Valores em unidades menores (escala do Money). `allocated` está na moeda do
 * pagamento; `document_amount` na moeda do documento — só diferem quando há
 * câmbio. Crédito aplicado (desconto, credit note) não movimenta dinheiro:
 * `allocated` fica 0 e o valor quitado vai só em `document_amount`.
 */
final class PaymentAllocationReport
{
    /**
     * @return array{
     *     payment: array{number: ?string, reference: ?string, date: ?string, currency: string, amount: int},
     *     rows: list<array<string, mixed>>,
     *     has_exchange: bool,
     *     show_document_amount: bool,
     *     totals: array{allocated: int, unallocated: int, credits: int},
     * }
     */
    public static function forPayment(Payment $payment): array
    {
        $payment->loadMissing([
            'allocations.creditItem',
            'allocations.scheduleItem.shipment',
            'allocations.scheduleItem.payable' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Shipment::class => ['items.proformaInvoiceItem.proformaInvoice'],
                    DebitNote::class => ['proformaInvoice', 'shipment'],
                    CreditNote::class => ['proformaInvoice', 'shipment'],
                ]);
            },
        ]);

        $currency = $payment->currency_code ?? 'USD';

        $rows = $payment->allocations
            ->sortBy('id')
            ->values()
            ->map(fn (PaymentAllocation $allocation, int $index) => self::row($allocation, $index + 1, $currency))
            ->all();

        $allocated = (int) $payment->allocations->sum('allocated_amount');
        $hasExchange = collect($rows)->contains(fn (array $row) => $row['exchange_rate'] !== null);

        return [
            'payment' => [
                'number' => $payment->number,
                'reference' => $payment->reference,
                'date' => $payment->payment_date?->format('d/m/Y'),
                'currency' => $currency,
                'amount' => (int) $payment->amount,
            ],
            'rows' => $rows,
            'has_exchange' => $hasExchange,
            // Coluna "valor no documento" só quando difere do alocado:
            // câmbio ou crédito aplicado (que não movimenta dinheiro).
            'show_document_amount' => $hasExchange || collect($rows)->contains('is_credit', true),
            'totals' => [
                'allocated' => $allocated,
                'unallocated' => max(0, (int) $payment->amount - $allocated),
                'credits' => (int) collect($rows)->where('is_credit', true)->sum('document_amount'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(PaymentAllocation $allocation, int $index, string $paymentCurrency): array
    {
        $item = $allocation->scheduleItem;
        $payable = $item?->payable;
        $shipment = self::shipment($item, $payable);
        $invoices = self::invoices($payable);

        $documentCurrency = $item?->currency_code ?: $paymentCurrency;
        $isCredit = $allocation->isCreditApplication();
        $allocated = (int) $allocation->allocated_amount;
        $documentAmount = (int) ($allocation->allocated_amount_in_document_currency ?: $allocated);

        // Custo do embarque (frete etc.) cobre várias PIs: mostra quanto do
        // valor desta linha cabe a cada uma. Crédito aplicado não tem valor
        // na moeda do pagamento, então rateia o valor do documento.
        $breakdown = $payable instanceof Shipment
            ? self::splitByInvoice(
                $payable,
                $invoices,
                $isCredit ? $documentAmount : $allocated,
                $isCredit ? $documentCurrency : $paymentCurrency,
            )
            : [];

        return [
            'index' => $index,
            'document_type' => self::documentType($payable),
            'document_ref' => $payable?->reference ?? '—',
            'pi_ref' => $invoices->pluck('reference')->filter()->unique()->implode(', ') ?: null,
            'pi_breakdown' => $breakdown,
            'client_reference' => self::clientReference($payable, $shipment, $invoices),
            'shipment_ref' => $shipment?->reference,
            'bl_number' => $shipment?->bl_number ?: null,
            'description' => self::description($item, $allocation),
            'is_credit' => $isCredit,
            'allocated' => $allocated,
            'payment_currency' => $paymentCurrency,
            'exchange_rate' => ! $isCredit && $documentCurrency !== $paymentCurrency && $allocation->exchange_rate !== null
                ? (float) $allocation->exchange_rate
                : null,
            'document_amount' => $documentAmount,
            'document_currency' => $documentCurrency,
        ];
    }

    private static function documentType(?Model $payable): string
    {
        return match (true) {
            $payable instanceof ProformaInvoice => __('navigation.models.proforma_invoice'),
            $payable instanceof Shipment => __('navigation.models.shipment'),
            $payable instanceof DebitNote => __('navigation.models.debit_note'),
            $payable instanceof CreditNote => __('navigation.models.credit_note'),
            $payable instanceof PurchaseOrder => __('navigation.models.purchase_order'),
            default => __('widgets.portal.document'),
        };
    }

    /** Embarque da parcela: o vinculado a ela ou o próprio documento. */
    private static function shipment(?PaymentScheduleItem $item, ?Model $payable): ?Shipment
    {
        return match (true) {
            $payable instanceof Shipment => $payable,
            $item?->shipment instanceof Shipment => $item->shipment,
            $payable instanceof DebitNote, $payable instanceof CreditNote => $payable->shipment,
            default => null,
        };
    }

    /**
     * PIs por trás do documento. Um embarque pode carregar várias.
     *
     * @return Collection<int, ProformaInvoice>
     */
    private static function invoices(?Model $payable): Collection
    {
        return match (true) {
            $payable instanceof ProformaInvoice => collect([$payable]),
            $payable instanceof Shipment => $payable->items
                ->map(fn ($shipmentItem) => $shipmentItem->proformaInvoiceItem?->proformaInvoice)
                ->filter()
                ->unique('id')
                ->values(),
            $payable instanceof DebitNote, $payable instanceof CreditNote => collect([$payable->proformaInvoice])->filter()->values(),
            default => collect(),
        };
    }

    /**
     * Rateia o valor de um custo do embarque entre as PIs que ele carrega,
     * na proporção do valor dos produtos de cada PI NESTE embarque (preço da
     * PI × quantidade embarcada) — a mesma chave de rateio do extrato
     * financeiro do embarque.
     *
     * As fatias saem em centavos inteiros e somam exatamente o valor da
     * linha: a sobra do arredondamento vai para as maiores frações. Sem
     * valor de produto para ratear, devolve vazio e a coluna só lista as PIs.
     *
     * @param  Collection<int, ProformaInvoice>  $invoices
     * @return list<array{reference: string, amount: int, currency: string, share_percent: float}>
     */
    private static function splitByInvoice(Shipment $shipment, Collection $invoices, int $amount, string $currency): array
    {
        $shares = app(ShipmentPaymentSummaryService::class)
            ->clientShareByProformaInvoice($shipment)
            ->only($invoices->pluck('id')->all());

        $total = (int) $shares->sum();

        if ($total <= 0 || $invoices->isEmpty()) {
            return [];
        }

        $cent = Money::SCALE / 100;
        $cents = intdiv($amount, $cent);

        $parts = $invoices
            ->sortBy('reference')
            ->values()
            ->map(function (ProformaInvoice $invoice) use ($shares, $total, $cents) {
                $exact = $cents * (int) $shares->get($invoice->id, 0) / $total;

                return ['invoice' => $invoice, 'share' => (int) $shares->get($invoice->id, 0), 'cents' => (int) floor($exact), 'fraction' => $exact - floor($exact)];
            });

        $leftover = $cents - (int) $parts->sum('cents');
        $bonus = $parts->sortByDesc('fraction')->take($leftover)->keys()->flip();
        // Valor com fração de centavo (raro): a fração fica na primeira fatia.
        $subCent = $amount - $cents * $cent;

        return $parts
            ->map(fn (array $part, int $key) => [
                'reference' => (string) $part['invoice']->reference,
                'amount' => ($part['cents'] + ($bonus->has($key) ? 1 : 0)) * $cent + ($key === 0 ? $subCent : 0),
                'currency' => $currency,
                'share_percent' => round($part['share'] / $total * 100, 2),
            ])
            ->all();
    }

    /**
     * Referência do cliente: a da PI; em custo de embarque, a do próprio
     * embarque quando preenchida, senão as das PIs que ele carrega.
     *
     * @param  Collection<int, ProformaInvoice>  $invoices
     */
    private static function clientReference(?Model $payable, ?Shipment $shipment, Collection $invoices): ?string
    {
        if ($payable instanceof Shipment && filled($shipment?->client_reference)) {
            return $shipment->client_reference;
        }

        return $invoices->pluck('client_reference')->filter()->unique()->implode(', ') ?: null;
    }

    /**
     * Rótulo da parcela sem o "[SH-… / PI-…]" do final: embarque, BL e PI já
     * têm coluna própria.
     */
    private static function description(?PaymentScheduleItem $item, PaymentAllocation $allocation): string
    {
        $label = self::cleanLabel($item?->label);

        if ($allocation->isCreditApplication()) {
            $credit = self::cleanLabel($allocation->creditItem?->label);

            return trim($label.' — '.__('widgets.portal.allocation_details.credit_applied').($credit !== '—' ? ': '.$credit : ''));
        }

        return $label;
    }

    private static function cleanLabel(?string $label): string
    {
        $label = preg_replace('/\s*\x{2014}?\s*\[[^\]]*\]/u', '', (string) $label);
        $label = (string) preg_replace('/^[\s\x{2014}]+|[\s\x{2014}]+$/u', '', (string) $label);

        return $label !== '' ? $label : '—';
    }
}
