<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Support\Collection;

/**
 * Totais de embalagem de um embarque (ou de um recorte dele, tipo um
 * container): quantos volumes, quantas caixas, peso e cubagem.
 *
 * Duas regras mandam aqui:
 *
 * 1. VOLUME é unidade de manuseio: caixa fora de pallet conta 1, pallet conta 1
 *    por mais caixas que leve. Pallet sem caixa não conta — é agrupador vazio,
 *    não bulto embarcado.
 * 2. PESO e CUBAGEM de carga paletizada são DO PALLET: o peso pesado na balança
 *    e o cubo do conjunto (que inclui estrado, folga entre caixas e overhang).
 *    Faltando peso ou medida no pallet, cai na soma das caixas em cima dele.
 *    O LÍQUIDO nunca vem do pallet: estrado não é mercadoria.
 *
 * Existe em duas formas que precisam devolver sempre o mesmo número:
 * fromCartons() para coleção já carregada (PDF, cards) e fromShipment() para
 * agregar no banco sem hidratar caixa — embarque grande tem milhares delas.
 *
 * @phpstan-type PackingTotals array{units: int, cartons: int, loose_cartons: int, pallets: int, gross: float, net: float, cbm: float}
 */
class PackingTotalsCalculator
{
    /**
     * @param  Collection<int, Carton>  $cartons  com a relação `pallet` carregada
     * @return array{units: int, cartons: int, loose_cartons: int, pallets: int, gross: float, net: float, cbm: float}
     */
    public function fromCartons(Collection $cartons): array
    {
        [$palletized, $loose] = $cartons->partition(
            fn (Carton $carton) => $carton->shipment_pallet_id !== null
        );

        $gross = (float) $loose->sum(fn (Carton $c) => (float) $c->gross_weight);
        $cbm = (float) $loose->sum(fn (Carton $c) => (float) $c->volume);
        $net = (float) $cartons->sum(fn (Carton $c) => (float) $c->net_weight);

        $groups = $palletized->groupBy('shipment_pallet_id');

        foreach ($groups as $onPallet) {
            $pallet = $onPallet->first()->pallet;
            $cartonsGross = (float) $onPallet->sum(fn (Carton $c) => (float) $c->gross_weight);
            $cartonsCbm = (float) $onPallet->sum(fn (Carton $c) => (float) $c->volume);

            // Pallet ausente (dado inconsistente) não pode zerar peso: soma as caixas.
            $gross += $pallet?->effectiveGrossWeight($cartonsGross) ?? $cartonsGross;
            $cbm += $pallet?->effectiveVolume($cartonsCbm) ?? $cartonsCbm;
        }

        return $this->totals(
            cartons: $cartons->count(),
            looseCartons: $loose->count(),
            pallets: $groups->count(),
            gross: $gross,
            net: $net,
            cbm: $cbm,
        );
    }

    /**
     * @return array{units: int, cartons: int, loose_cartons: int, pallets: int, gross: float, net: float, cbm: float}
     */
    public function fromShipment(Shipment $shipment): array
    {
        $row = $shipment->cartons()
            ->selectRaw('COUNT(*) AS cartons')
            ->selectRaw('COUNT(CASE WHEN shipment_pallet_id IS NULL THEN 1 END) AS loose_cartons')
            ->selectRaw('COALESCE(SUM(net_weight), 0) AS net')
            ->selectRaw('COALESCE(SUM(CASE WHEN shipment_pallet_id IS NULL THEN gross_weight END), 0) AS loose_gross')
            ->selectRaw('COALESCE(SUM(CASE WHEN shipment_pallet_id IS NULL THEN volume END), 0) AS loose_cbm')
            ->first();

        $gross = (float) $row->loose_gross;
        $cbm = (float) $row->loose_cbm;
        $pallets = 0;

        // Só os pallets que realmente carregam caixa; o join já descarta os vazios.
        foreach ($this->palletsWithCartonSums($shipment) as $pallet) {
            $pallets++;
            $gross += $pallet->effectiveGrossWeight((float) $pallet->cartons_gross);
            $cbm += $pallet->effectiveVolume((float) $pallet->cartons_cbm);
        }

        return $this->totals(
            cartons: (int) $row->cartons,
            looseCartons: (int) $row->loose_cartons,
            pallets: $pallets,
            gross: $gross,
            net: (float) $row->net,
            cbm: $cbm,
        );
    }

    /**
     * @return Collection<int, ShipmentPallet>
     */
    private function palletsWithCartonSums(Shipment $shipment): Collection
    {
        return ShipmentPallet::query()
            ->join('cartons', 'cartons.shipment_pallet_id', '=', 'shipment_pallets.id')
            ->where('shipment_pallets.shipment_id', $shipment->getKey())
            ->groupBy('shipment_pallets.id')
            ->select('shipment_pallets.*')
            ->selectRaw('COALESCE(SUM(cartons.gross_weight), 0) AS cartons_gross')
            ->selectRaw('COALESCE(SUM(cartons.volume), 0) AS cartons_cbm')
            ->get();
    }

    /**
     * @return array{units: int, cartons: int, loose_cartons: int, pallets: int, gross: float, net: float, cbm: float}
     */
    private function totals(int $cartons, int $looseCartons, int $pallets, float $gross, float $net, float $cbm): array
    {
        return [
            'units' => $looseCartons + $pallets,
            'cartons' => $cartons,
            'loose_cartons' => $looseCartons,
            'pallets' => $pallets,
            'gross' => round($gross, 3),
            'net' => round($net, 3),
            'cbm' => round($cbm, 6),
        ];
    }
}
