<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Services\PackingTotalsCalculator;
use Illuminate\Console\Command;

/**
 * Regrava os totais dos embarques que usam pallet.
 *
 * Duas regras mudaram depois que esses valores foram gravados: total_packages
 * passou a guardar VOLUMES (caixa fora de pallet = 1, pallet = 1 quantas
 * caixas leve em cima) em vez da contagem crua de caixas, e o peso/cubagem de
 * carga paletizada passou a vir do pallet em vez da soma das caixas. O
 * recálculo só acontece sozinho quando alguém mexe numa caixa — este comando
 * corrige de uma vez.
 *
 * Só toca em embarque com pelo menos uma caixa em pallet: nos demais as regras
 * novas dão exatamente o mesmo número.
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class RecalculatePackageTotalsCommand extends Command
{
    protected $signature = 'shipments:recalculate-package-totals {--apply : Persist the changes (otherwise dry-run)}';

    protected $description = 'Rewrite shipment totals for palletized cargo (a pallet is one package, and carries its own weight and cubic)';

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
        $calculator = app(PackingTotalsCalculator::class);
        $rows = [];
        $changed = 0;

        foreach (Shipment::whereIn('id', $shipmentIds)->orderBy('reference')->get() as $shipment) {
            $totals = $calculator->fromShipment($shipment);

            $before = [
                (int) $shipment->total_packages,
                round((float) $shipment->total_gross_weight, 3),
                round((float) $shipment->total_volume, 4),
            ];
            $after = [
                $totals['units'],
                round($totals['gross'], 3),
                round($totals['cbm'], 4),
            ];

            if ($before === $after) {
                continue;
            }

            $changed++;
            $rows[] = [
                $shipment->reference,
                $this->change((string) $before[0], (string) $after[0]),
                $this->change(number_format($before[1], 2), number_format($after[1], 2)),
                $this->change(number_format($before[2], 3), number_format($after[2], 3)),
                $totals['cartons'].' em '.$totals['pallets'].' pallet(s)',
            ];

            if ($apply) {
                $recalc->execute($shipment);
            }
        }

        if ($rows === []) {
            $this->info('Todos os embarques com pallet já estão com os totais corretos.');

            return self::SUCCESS;
        }

        $this->table(['Embarque', 'Volumes', 'GW (kg)', 'CBM', 'Caixas'], $rows);
        $this->line($apply
            ? "Atualizados: {$changed}."
            : "Seriam atualizados: {$changed}. Rode com --apply para gravar.");

        return self::SUCCESS;
    }

    private function change(string $before, string $after): string
    {
        return $before === $after ? $before : $before.' → '.$after;
    }
}
