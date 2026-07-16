<?php

namespace App\Domain\Financial\Services;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fonte única da matemática "quanto o schedule base DEVERIA somar".
 *
 * Usada tanto pela regeneração (GeneratePaymentScheduleAction) quanto pela
 * detecção de schedule desatualizado (HasPaymentSchedule::isScheduleStale) —
 * as duas nunca podem divergir, senão a detecção acusa staleness que o
 * regenerate não corrige (ou vice-versa).
 *
 * Invariante do regenerate: para cada stage do payment term, a soma dos itens
 * base daquele stage (item base cheio, OU ship-specifics + [remaining], OU
 * item coberto pago) é sempre round(total × pct/100) — a partição por
 * embarque não altera o total do stage.
 */
class ScheduleExpectationCalculator
{
    /**
     * Document total straight from the DB — avoids Eloquent caching.
     * Mirrors what regenerateBaseItems() uses as the base amount.
     */
    public function documentTotalFromDb(Model $payable): int
    {
        if ($payable instanceof ProformaInvoice) {
            return (int) $payable->items()
                ->selectRaw('SUM(unit_price * quantity) as total')
                ->value('total');
        }

        if ($payable instanceof PurchaseOrder) {
            return (int) $payable->items()
                ->selectRaw('SUM(unit_cost * quantity) as total')
                ->value('total');
        }

        return 0;
    }

    /**
     * Expected sum of all term-stage-sourced schedule items for the payable,
     * or null when it cannot be computed (no payment term / no stages).
     */
    public function expectedBaseTotal(Model $payable): ?int
    {
        if ($payable instanceof Shipment) {
            return $this->expectedBaseTotalForShipment($payable);
        }

        $paymentTerm = $payable->paymentTerm()->with('stages')->first();

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return null;
        }

        $total = $this->documentTotalFromDb($payable);

        return (int) $paymentTerm->stages->sum(
            fn ($stage) => (int) round($total * ($stage->percentage / 100))
        );
    }

    /**
     * Stage IDs the payable's schedule items are allowed to reference.
     * Items pointing at any other stage mean the payment term changed
     * (swap or stage edit) and the schedule was never regenerated.
     *
     * @return array<int>|null null when not determinable
     */
    public function currentStageIds(Model $payable): ?array
    {
        if ($payable instanceof Shipment) {
            $stageIds = [];

            foreach ($this->shipmentValuesByPi($payable) as $group) {
                foreach ($group['stages'] as $stage) {
                    $stageIds[] = $stage->id;
                }
            }

            return array_values(array_unique($stageIds));
        }

        $paymentTerm = $payable->paymentTerm()->with('stages')->first();

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return null;
        }

        return $paymentTerm->stages->pluck('id')->all();
    }

    /**
     * Shipment-owned mirror items cover only the shipment-dependent stages,
     * per PI group — same grouping and value math as executeForShipment().
     */
    protected function expectedBaseTotalForShipment(Shipment $shipment): ?int
    {
        $groups = $this->shipmentValuesByPi($shipment);

        if ($groups === []) {
            return null;
        }

        $expected = 0;

        foreach ($groups as $group) {
            foreach ($group['stages'] as $stage) {
                $expected += (int) round($group['value'] * ($stage->percentage / 100));
            }
        }

        return $expected;
    }

    /**
     * @return array<int, array{value: int, stages: \Illuminate\Support\Collection}>
     */
    protected function shipmentValuesByPi(Shipment $shipment): array
    {
        $shipment->loadMissing(['items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages']);

        $groups = [];

        $itemsByPi = $shipment->items
            ->filter(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice)
            ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id);

        foreach ($itemsByPi as $piId => $shipmentItems) {
            $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;
            $paymentTerm = $pi->paymentTerm;

            if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
                continue;
            }

            $piValue = (int) $shipmentItems->sum(function ($item) {
                $piItem = $item->proformaInvoiceItem;

                return $piItem ? $piItem->unit_price * $item->quantity : 0;
            });

            if ($piValue <= 0) {
                continue;
            }

            $shipmentStages = $paymentTerm->stages->filter(
                fn ($stage) => $stage->calculation_base?->isShipmentDependent()
            );

            if ($shipmentStages->isEmpty()) {
                continue;
            }

            $groups[$piId] = ['value' => $piValue, 'stages' => $shipmentStages];
        }

        return $groups;
    }
}
