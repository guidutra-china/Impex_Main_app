<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Logistics\Models\Carton;
use Illuminate\Support\Collection;

/**
 * Conta os VOLUMES (unidades de manuseio) de um embarque — o número que vai
 * como "packages"/"bultos" no packing list, na CI e no BL.
 *
 * Regra: uma caixa fora de pallet é um volume; um pallet é UM volume, não
 * importa quantas caixas leve — as caixas paletizadas não são contadas
 * separadamente. Um pallet sem nenhuma caixa não é volume nenhum: ele é só um
 * agrupador vazio na tela, não um bulto embarcado.
 *
 * Fonte única da regra: quem contar volumes usa esta classe (coleção em
 * memória) ou a expressão SQL abaixo (agregação no banco). As duas devem
 * sempre dar o mesmo número.
 */
class ShippingUnitCounter
{
    /**
     * Mesma regra em SQL, para agregar sem hidratar milhares de cartons.
     * COUNT(DISTINCT) ignora NULL, então cobre exatamente os pallets usados.
     */
    public const SQL = '(COUNT(CASE WHEN shipment_pallet_id IS NULL THEN 1 END) + COUNT(DISTINCT shipment_pallet_id))';

    /**
     * @param  Collection<int, Carton>  $cartons
     */
    public static function forCartons(Collection $cartons): int
    {
        return self::breakdown($cartons)['units'];
    }

    /**
     * @param  Collection<int, Carton>  $cartons
     * @return array{units: int, cartons: int, loose_cartons: int, pallets: int}
     */
    public static function breakdown(Collection $cartons): array
    {
        $palletIds = $cartons
            ->map(fn (Carton $carton) => $carton->shipment_pallet_id)
            ->filter()
            ->unique();

        $loose = $cartons->count() - $cartons->filter(fn (Carton $carton) => $carton->shipment_pallet_id !== null)->count();
        $pallets = $palletIds->count();

        return [
            'units' => $loose + $pallets,
            'cartons' => $cartons->count(),
            'loose_cartons' => $loose,
            'pallets' => $pallets,
        ];
    }
}
