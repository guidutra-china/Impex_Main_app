<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Services\ShippingUnitCounter;
use Illuminate\Console\Command;

/**
 * Reconta shipments.total_packages nos embarques que usam pallet.
 *
 * O campo passou a guardar VOLUMES (caixa fora de pallet = 1, pallet = 1,
 * quantas caixas leve em cima) em vez da contagem crua de caixas. Quem já
 * tinha o valor gravado pela regra antiga só é corrigido quando alguma caixa
 * é mexida — este comando corrige de uma vez.
 *
 * Só toca em embarque com pelo menos uma caixa em pallet: nos demais as duas
 * regras dão o mesmo número.
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class RecalculatePackageTotalsCommand extends Command
{
    protected $signature = 'shipments:recalculate-package-totals {--apply : Persist the changes (otherwise dry-run)}';

    protected $description = 'Recount shipments.total_packages as shipping units (a pallet is one package, not N boxes)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $shipmentIds = Carton::query()
            ->whereNotNull('shipment_pallet_id')
            ->distinct()
            ->pluck('shipment_id');

        if ($shipmentIds->isEmpty()) {
            $this->info('Nenhum embarque com caixa em pallet — nada a fazer.');

            return self::SUCCESS;
        }

        $recalc = app(RecalculateShipmentTotalsAction::class);
        $rows = [];
        $changed = 0;

        foreach (Shipment::whereIn('id', $shipmentIds)->orderBy('reference')->get() as $shipment) {
            $units = (int) $shipment->cartons()
                ->selectRaw(ShippingUnitCounter::SQL.' AS units')
                ->value('units');

            $stored = $shipment->total_packages;

            if ((int) $stored === $units) {
                continue;
            }

            $changed++;
            $rows[] = [$shipment->reference, $stored ?? '—', $units, $shipment->cartons()->count()];

            if ($apply) {
                $recalc->execute($shipment);
            }
        }

        if ($rows === []) {
            $this->info('Todos os embarques com pallet já estão com o total de volumes correto.');

            return self::SUCCESS;
        }

        $this->table(['Embarque', 'total_packages antes', 'volumes', 'caixas'], $rows);
        $this->line($apply
            ? "Atualizados: {$changed}."
            : "Seriam atualizados: {$changed}. Rode com --apply para gravar.");

        return self::SUCCESS;
    }
}
