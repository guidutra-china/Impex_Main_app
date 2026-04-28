<?php

namespace App\Console\Commands;

use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Console\Command;

class BackfillQuotationFxSnapshotsCommand extends Command
{
    protected $signature = 'quotations:backfill-fx-snapshots
        {--dry-run : Do not persist changes}
        {--quotation= : Limit to a specific quotation id}
        {--report= : Write per-row decisions to this CSV path}';

    protected $description = 'Backfill cost_currency_code and cost_exchange_rate on legacy quotation_items and quotation_item_suppliers';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $reportPath = $this->option('report');
        $reportRows = [];

        $itemsQuery = QuotationItem::query()
            ->whereNull('cost_currency_code')
            ->with('supplierQuotationItem.supplierQuotation', 'quotation');
        if ($this->option('quotation')) {
            $itemsQuery->where('quotation_id', (int) $this->option('quotation'));
        }

        $stats = ['resolved' => 0, 'legacy' => 0, 'missing' => 0];

        $itemsQuery->chunkById(200, function ($items) use (&$stats, &$reportRows, $isDryRun) {
            foreach ($items as $item) {
                $sourceCurrency = $item->supplierQuotationItem?->supplierQuotation?->currency_code;
                $bucket = 'resolved';
                if ($sourceCurrency === null) {
                    $sourceCurrency = $item->quotation->currency_code;
                    $bucket = 'legacy';
                }

                $rate = 1.0;
                if ($sourceCurrency !== $item->quotation->currency_code) {
                    $source = Currency::findByCode($sourceCurrency);
                    $target = Currency::findByCode($item->quotation->currency_code);
                    if ($source && $target) {
                        $converted = ExchangeRate::convert(
                            $source->id, $target->id, 1.0,
                            optional($item->quotation->created_at)->toDateString(),
                        ) ?? ExchangeRate::convert(
                            $source->id, $target->id, 1.0,
                            optional($item->supplierQuotationItem?->supplierQuotation?->created_at)->toDateString(),
                        );
                        if ($converted !== null) {
                            $rate = (float) $converted;
                        } else {
                            $bucket = 'missing';
                        }
                    } else {
                        $bucket = 'missing';
                    }
                }

                if (! $isDryRun) {
                    $item->update([
                        'cost_currency_code' => $sourceCurrency,
                        'cost_exchange_rate' => $rate,
                    ]);
                }

                $stats[$bucket]++;
                $reportRows[] = [
                    'quotation_item_id' => $item->id,
                    'bucket' => $bucket,
                    'cost_currency_code' => $sourceCurrency,
                    'cost_exchange_rate' => $rate,
                ];
            }
        });

        $suppliersStats = ['resolved' => 0, 'missing' => 0];
        QuotationItemSupplier::query()
            ->whereNull('cost_exchange_rate')
            ->with('quotationItem.quotation')
            ->chunkById(200, function ($rows) use (&$suppliersStats, &$reportRows, $isDryRun) {
                foreach ($rows as $row) {
                    $rate = 1.0;
                    $bucket = 'resolved';
                    if ($row->currency_code !== $row->quotationItem->quotation->currency_code) {
                        $source = Currency::findByCode($row->currency_code);
                        $target = Currency::findByCode($row->quotationItem->quotation->currency_code);
                        if ($source && $target) {
                            $converted = ExchangeRate::convert(
                                $source->id, $target->id, 1.0,
                                optional($row->quotationItem->quotation->created_at)->toDateString(),
                            );
                            if ($converted !== null) {
                                $rate = (float) $converted;
                            } else {
                                $bucket = 'missing';
                            }
                        } else {
                            $bucket = 'missing';
                        }
                    }
                    if (! $isDryRun) {
                        $row->update(['cost_exchange_rate' => $rate]);
                    }
                    $suppliersStats[$bucket]++;
                }
            });

        $this->line(sprintf('%d quotation items processed', array_sum($stats)));
        $this->line(sprintf('  ✓ Resolved from SQ source:        %d', $stats['resolved']));
        $this->line(sprintf('  ⚠ Legacy (no source SQ):          %d', $stats['legacy']));
        $this->line(sprintf('  ⚠ Missing FX rate at quote date:  %d', $stats['missing']));
        $this->newLine();
        $this->line(sprintf('%d quotation item suppliers processed', array_sum($suppliersStats)));
        $this->line(sprintf('  ✓ Resolved:                       %d', $suppliersStats['resolved']));
        $this->line(sprintf('  ⚠ Missing FX rate:                %d', $suppliersStats['missing']));

        if ($isDryRun) {
            $this->newLine();
            $this->info('Dry run — no changes persisted.');
        }

        if ($reportPath) {
            $fh = fopen($reportPath, 'w');
            fputcsv($fh, ['quotation_item_id', 'bucket', 'cost_currency_code', 'cost_exchange_rate']);
            foreach ($reportRows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->line(sprintf('Report written to %s', $reportPath));
        }

        return self::SUCCESS;
    }
}
