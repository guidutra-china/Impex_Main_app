<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Collection;

/**
 * Extrato financeiro do embarque — documento de CLIENTE.
 *
 * Nenhum número de custo nosso, custo landed ou margem pode entrar neste
 * payload. Análise interna é o widget LandedCostCalculator; este documento sai
 * para fora da empresa.
 *
 * Spec: docs/superpowers/specs/2026-08-31-shipment-financial-statement-design.md
 */
class ShipmentFinancialStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.shipment-financial-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Financial Statement';
    }

    public function getDocumentType(): string
    {
        return 'shipment_financial_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "FS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.proformaInvoice',
        ]);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $shares = app(ShipmentPaymentSummaryService::class)
            ->clientShareByProformaInvoice($shipment);

        $proformaInvoices = ProformaInvoice::query()
            ->whereIn('id', $shares->keys())
            ->orderBy('reference')
            ->get();

        $goods = $this->buildGoods($proformaInvoices, $shares, $currencyCode);
        $goodsTotal = (int) collect($goods)->where('in_totals', true)->sum('raw_amount');

        return [
            'shipment' => $this->buildShipmentBlock($shipment, $currencyCode),
            'client' => ['name' => $shipment->company?->name ?? '—'],
            'goods' => $goods,
            'goods_total' => $this->formatMoney($goodsTotal, $currencyCode, 2),
            'raw_goods_total' => $goodsTotal,
            'has_foreign_currency_pis' => collect($goods)->contains(fn (array $row) => ! $row['in_totals']),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShipmentBlock(Shipment $shipment, string $currencyCode): array
    {
        return [
            'reference' => $shipment->reference,
            'client_reference' => $shipment->client_reference ?: '—',
            'issue_date' => $this->formatDate($shipment->issue_date),
            'currency_code' => $currencyCode,
            'transport_mode' => $shipment->transport_mode?->getLabel() ?? '—',
            'incoterm' => $shipment->incoterm?->value ?? '—',
            'bl_number' => $shipment->bl_number ?: '—',
            'vessel' => $shipment->vessel_name ?: '—',
            'voyage' => $shipment->voyage_number ?: '—',
            'origin_port' => $shipment->origin_port ?: '—',
            'destination_port' => $shipment->destination_port ?: '—',
            'etd' => $this->formatDate($shipment->etd),
            'eta' => $this->formatDate($shipment->eta),
            'packages' => $shipment->total_packages !== null ? (string) $shipment->total_packages : '—',
            'gross_weight' => $shipment->total_gross_weight !== null
                ? number_format((float) $shipment->total_gross_weight, 3).' kg'
                : '—',
            'volume' => $shipment->total_volume !== null
                ? number_format((float) $shipment->total_volume, 4).' CBM'
                : '—',
        ];
    }

    /**
     * @param  Collection<int, ProformaInvoice>  $proformaInvoices
     * @param  Collection<int, int>  $shares
     * @return array<int, array<string, mixed>>
     */
    private function buildGoods(Collection $proformaInvoices, Collection $shares, string $currencyCode): array
    {
        return $proformaInvoices->values()->map(function (ProformaInvoice $pi, int $index) use ($shares, $currencyCode) {
            $amount = (int) $shares->get($pi->id, 0);
            $piCurrency = $pi->currency_code ?? $currencyCode;

            return [
                'index' => $index + 1,
                'reference' => $pi->reference,
                'client_reference' => $pi->client_reference ?: '—',
                'currency_code' => $piCurrency,
                'amount' => $this->formatMoney($amount, $piCurrency, 2),
                'raw_amount' => $amount,
                'in_totals' => $piCurrency === $currencyCode,
            ];
        })->all();
    }
}
