<?php

namespace App\Domain\Infrastructure\Console;

use App\Domain\Infrastructure\Models\PaymentAllocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileBalancesCommand extends Command
{
    protected $signature = 'impex:reconcile-balances
                            {--fix : Actually fix the drift instead of just reporting}
                            {--type= : Only reconcile a specific payable type}';

    protected $description = 'Detect and optionally fix drift between cached paid_amount and computed allocations';

    public function handle(): int
    {
        $fix = $this->option('fix');
        $typeFilter = $this->option('type');

        $query = PaymentAllocation::query()
            ->select('payable_type', 'payable_id', DB::raw('SUM(amount) as computed_paid'))
            ->groupBy('payable_type', 'payable_id');

        if ($typeFilter) {
            $query->where('payable_type', $typeFilter);
        }

        $allocations = $query->get();

        $driftCount = 0;
        $fixedCount = 0;

        foreach ($allocations as $row) {
            $modelClass = $row->payable_type;

            if (! class_exists($modelClass)) {
                $this->warn("Unknown payable type: {$modelClass}");
                continue;
            }

            $model = $modelClass::find($row->payable_id);

            if (! $model) {
                $this->warn("Missing model: {$modelClass}#{$row->payable_id}");
                continue;
            }

            if (! isset($model->attributes['paid_amount'])) {
                continue;
            }

            $cached = $model->paid_amount;
            $computed = (int) $row->computed_paid;

            if ($cached !== $computed) {
                $driftCount++;
                $drift = $cached - $computed;

                $this->line(
                    "<comment>DRIFT</comment> {$modelClass}#{$row->payable_id}: "
                    . "cached={$cached}, computed={$computed}, drift={$drift}"
                );

                if ($fix) {
                    $model->updateQuietly(['paid_amount' => $computed]);
                    $fixedCount++;
                    $this->line("  <info>FIXED</info>");
                }
            }
        }

        $this->newLine();

        if ($driftCount === 0) {
            $this->info('No balance drift detected. All cached values are consistent.');
        } else {
            $this->warn("Found {$driftCount} payables with balance drift.");
            if ($fix) {
                $this->info("Fixed {$fixedCount} records.");
            } else {
                $this->line('Run with --fix to correct the drift.');
            }
        }

        return self::SUCCESS;
    }
}
