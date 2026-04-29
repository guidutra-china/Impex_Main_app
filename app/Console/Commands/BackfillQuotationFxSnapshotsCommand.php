<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillQuotationFxSnapshotsCommand extends Command
{
    protected $signature = 'quotations:backfill-fx-snapshots
        {--dry-run : Do not persist changes}
        {--quotation= : Limit to a specific quotation id}
        {--report= : Write per-row decisions to this CSV path}';

    protected $description = 'Backfill cost_currency_code, cost_exchange_rate, and *_captured_at on legacy FX snapshots across quotation_items, quotation_item_suppliers, proforma_invoice_items, and payment_allocations';

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
                        'cost_exchange_rate_captured_at' => optional($item->quotation->created_at)->toDateString(),
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
                        $row->update([
                            'cost_exchange_rate' => $rate,
                            'cost_exchange_rate_captured_at' => optional($row->quotationItem->quotation->created_at)->toDateString(),
                        ]);
                    }
                    $suppliersStats[$bucket]++;
                }
            });

        $capturedAtStats = $this->backfillCapturedAtOnly($isDryRun);

        $this->line(sprintf('%d quotation items processed', array_sum($stats)));
        $this->line(sprintf('  ✓ Resolved from SQ source:        %d', $stats['resolved']));
        $this->line(sprintf('  ⚠ Legacy (no source SQ):          %d', $stats['legacy']));
        $this->line(sprintf('  ⚠ Missing FX rate at quote date:  %d', $stats['missing']));
        $this->newLine();
        $this->line(sprintf('%d quotation item suppliers processed', array_sum($suppliersStats)));
        $this->line(sprintf('  ✓ Resolved:                       %d', $suppliersStats['resolved']));
        $this->line(sprintf('  ⚠ Missing FX rate:                %d', $suppliersStats['missing']));
        $this->newLine();
        $this->line('Captured-at backfill (only rows missing the new column):');
        $this->line(sprintf('  quotation_items:                  %d', $capturedAtStats['quotation_items']));
        $this->line(sprintf('  quotation_item_suppliers:         %d', $capturedAtStats['quotation_item_suppliers']));
        $this->line(sprintf('  proforma_invoice_items:           %d', $capturedAtStats['proforma_invoice_items']));
        $this->line(sprintf('  payment_allocations:              %d', $capturedAtStats['payment_allocations']));

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

    /**
     * Fill *_captured_at columns on rows where it is null but the parent
     * document has a usable date. Independent from the FX-rate backfill
     * above: covers PI items and payment allocations, plus any quotation
     * rows that already had a rate but missed the new column.
     *
     * @return array{quotation_items: int, quotation_item_suppliers: int, proforma_invoice_items: int, payment_allocations: int}
     */
    protected function backfillCapturedAtOnly(bool $isDryRun): array
    {
        $stats = [
            'quotation_items' => 0,
            'quotation_item_suppliers' => 0,
            'proforma_invoice_items' => 0,
            'payment_allocations' => 0,
        ];

        QuotationItem::query()
            ->whereNull('cost_exchange_rate_captured_at')
            ->whereNotNull('cost_exchange_rate')
            ->with('quotation:id,created_at')
            ->chunkById(500, function ($rows) use (&$stats, $isDryRun) {
                foreach ($rows as $row) {
                    $date = optional($row->quotation?->created_at)->toDateString();
                    if (! $date) {
                        continue;
                    }
                    if (! $isDryRun) {
                        DB::table('quotation_items')->where('id', $row->id)
                            ->update(['cost_exchange_rate_captured_at' => $date]);
                    }
                    $stats['quotation_items']++;
                }
            });

        QuotationItemSupplier::query()
            ->whereNull('cost_exchange_rate_captured_at')
            ->whereNotNull('cost_exchange_rate')
            ->with('quotationItem.quotation:id,created_at')
            ->chunkById(500, function ($rows) use (&$stats, $isDryRun) {
                foreach ($rows as $row) {
                    $date = optional($row->quotationItem?->quotation?->created_at)->toDateString();
                    if (! $date) {
                        continue;
                    }
                    if (! $isDryRun) {
                        DB::table('quotation_item_suppliers')->where('id', $row->id)
                            ->update(['cost_exchange_rate_captured_at' => $date]);
                    }
                    $stats['quotation_item_suppliers']++;
                }
            });

        ProformaInvoiceItem::query()
            ->whereNull('cost_exchange_rate_captured_at')
            ->whereNotNull('cost_exchange_rate')
            ->with('proformaInvoice:id,issue_date,created_at')
            ->chunkById(500, function ($rows) use (&$stats, $isDryRun) {
                foreach ($rows as $row) {
                    $pi = $row->proformaInvoice;
                    $date = optional($pi?->issue_date)->toDateString()
                        ?? optional($pi?->created_at)->toDateString();
                    if (! $date) {
                        continue;
                    }
                    if (! $isDryRun) {
                        DB::table('proforma_invoice_items')->where('id', $row->id)
                            ->update(['cost_exchange_rate_captured_at' => $date]);
                    }
                    $stats['proforma_invoice_items']++;
                }
            });

        PaymentAllocation::query()
            ->whereNull('exchange_rate_captured_at')
            ->whereNotNull('exchange_rate')
            ->with('payment:id,payment_date,created_at')
            ->chunkById(500, function ($rows) use (&$stats, $isDryRun) {
                foreach ($rows as $row) {
                    $payment = $row->payment;
                    $date = optional($payment?->payment_date)->toDateString()
                        ?? optional($payment?->created_at)->toDateString();
                    if (! $date) {
                        continue;
                    }
                    if (! $isDryRun) {
                        DB::table('payment_allocations')->where('id', $row->id)
                            ->update(['exchange_rate_captured_at' => $date]);
                    }
                    $stats['payment_allocations']++;
                }
            });

        return $stats;
    }
}
