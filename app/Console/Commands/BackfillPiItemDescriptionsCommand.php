<?php

namespace App\Console\Commands;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Console\Command;

class BackfillPiItemDescriptionsCommand extends Command
{
    protected $signature = 'proforma-invoices:backfill-item-descriptions
        {--dry-run : Do not persist changes}
        {--pi= : Limit to a specific proforma invoice id}';

    protected $description = 'One-time backfill: refresh DRAFT proforma invoice item descriptions that diverged from the current product name after product renames';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $query = ProformaInvoiceItem::query()
            ->whereNotNull('product_id')
            ->whereHas('proformaInvoice', function ($q) {
                $q->where('status', ProformaInvoiceStatus::DRAFT);
            })
            ->whereHas('product', function ($q) {
                $q->whereColumn('products.name', '!=', 'proforma_invoice_items.description');
            })
            ->with(['product', 'proformaInvoice']);

        if ($this->option('pi')) {
            $query->where('proforma_invoice_id', (int) $this->option('pi'));
        }

        $updated = 0;

        $query->chunkById(200, function ($items) use (&$updated, $isDryRun) {
            foreach ($items as $item) {
                $this->line(sprintf(
                    '%s item #%d: "%s" -> "%s"',
                    $item->proformaInvoice?->reference ?? 'PI?',
                    $item->id,
                    $item->description,
                    $item->product->name,
                ));

                if (! $isDryRun) {
                    $item->update(['description' => $item->product->name]);
                }

                $updated++;
            }
        });

        $this->info(sprintf(
            '%s%d proforma invoice item(s) updated.',
            $isDryRun ? '[DRY RUN] ' : '',
            $updated,
        ));

        return self::SUCCESS;
    }
}
