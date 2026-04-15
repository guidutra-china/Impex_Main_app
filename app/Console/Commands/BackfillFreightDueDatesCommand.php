<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillFreightDueDatesCommand extends Command
{
    protected $signature = 'financial:backfill-freight-due-dates
        {--dry-run : Show changes without applying}
        {--force : Overwrite existing due_dates (default: only fill NULLs)}';

    protected $description = 'Set due_date to ETA - 3 days on all PaymentScheduleItems generated from FREIGHT additional costs whose payable is a Shipment with an ETA';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // 1. Find all FREIGHT AdditionalCost ids
        $freightCostIds = AdditionalCost::query()
            ->where('cost_type', AdditionalCostType::FREIGHT->value)
            ->pluck('id');

        if ($freightCostIds->isEmpty()) {
            $this->info('No FREIGHT additional costs found. Nothing to do.');

            return self::SUCCESS;
        }

        // 2. Find all PaymentScheduleItems sourced from those costs and attached to a Shipment
        $items = PaymentScheduleItem::query()
            ->where('source_type', AdditionalCost::class)
            ->whereIn('source_id', $freightCostIds)
            ->where('payable_type', Shipment::class)
            ->with('payable')
            ->get();

        $this->info("Found {$items->count()} freight schedule items linked to shipments.");

        $updated = 0;
        $skippedNoEta = 0;
        $skippedHasDate = 0;
        $skippedSame = 0;

        foreach ($items as $item) {
            /** @var Shipment|null $shipment */
            $shipment = $item->payable;
            if (! $shipment || ! $shipment->eta) {
                $skippedNoEta++;
                continue;
            }

            $newDueDate = Carbon::parse($shipment->eta)->subDays(3)->startOfDay();

            if ($item->due_date && ! $force) {
                $skippedHasDate++;
                continue;
            }

            if ($item->due_date && Carbon::parse($item->due_date)->isSameDay($newDueDate)) {
                $skippedSame++;
                continue;
            }

            $oldStr = $item->due_date ? Carbon::parse($item->due_date)->format('Y-m-d') : 'NULL';
            $newStr = $newDueDate->format('Y-m-d');

            $this->line("  #{$item->id}  {$shipment->reference}  {$oldStr} → {$newStr}");

            if (! $dryRun) {
                $item->due_date = $newDueDate;
                $item->save();
            }

            $updated++;
        }

        $this->newLine();
        $this->info("Updated:           {$updated}");
        $this->info("Skipped (no ETA):  {$skippedNoEta}");
        $this->info("Skipped (already): {$skippedHasDate}  (use --force to overwrite)");
        $this->info("Skipped (unchanged): {$skippedSame}");

        if ($dryRun) {
            $this->warn('Dry-run mode: no rows were actually written.');
        }

        return self::SUCCESS;
    }
}
