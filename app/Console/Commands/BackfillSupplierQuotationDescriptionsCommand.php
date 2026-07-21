<?php

namespace App\Console\Commands;

use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Console\Command;

class BackfillSupplierQuotationDescriptionsCommand extends Command
{
    protected $signature = 'supplier-quotations:backfill-descriptions
        {--dry-run : Do not persist changes}';

    protected $description = 'One-time backfill: copy the linked inquiry description into supplier quotations created before the description column existed';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $stats = ['updated' => 0, 'no_inquiry_description' => 0];

        SupplierQuotation::query()
            ->whereNull('description')
            ->whereNotNull('inquiry_id')
            ->with('inquiry')
            ->chunkById(200, function ($quotations) use (&$stats, $isDryRun) {
                foreach ($quotations as $sq) {
                    $description = $sq->inquiry?->description;

                    if (blank($description)) {
                        $stats['no_inquiry_description']++;

                        continue;
                    }

                    $this->line(sprintf(
                        '%s: "%s" (from %s)',
                        $sq->reference,
                        $description,
                        $sq->inquiry->reference,
                    ));

                    if (! $isDryRun) {
                        $sq->update(['description' => $description]);
                    }

                    $stats['updated']++;
                }
            });

        $this->info(sprintf(
            '%s%d supplier quotation(s) updated, %d skipped (inquiry without description).',
            $isDryRun ? '[DRY RUN] ' : '',
            $stats['updated'],
            $stats['no_inquiry_description'],
        ));

        return self::SUCCESS;
    }
}
