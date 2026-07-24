<?php

namespace App\Console\Commands;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Console\Command;

class BackfillPiItemDescriptionsCommand extends Command
{
    protected $signature = 'proforma-invoices:backfill-item-descriptions
        {--dry-run : Do not persist changes}
        {--pi= : Limit to a specific proforma invoice (id or reference, any status)}
        {--all-statuses : Include PIs beyond DRAFT (sent, confirmed, shipped, ...)}';

    protected $description = 'One-time backfill: refresh proforma invoice item descriptions that diverged from the current product name after product renames (DRAFT only by default)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $query = ProformaInvoiceItem::query()
            ->whereNotNull('product_id')
            ->whereHas('product', function ($q) {
                $q->whereColumn('products.name', '!=', 'proforma_invoice_items.description');
            })
            ->with(['product', 'proformaInvoice']);

        if ($this->option('pi')) {
            $pi = $this->resolvePi((string) $this->option('pi'));

            if ($pi === null) {
                $this->error("Proforma invoice '{$this->option('pi')}' not found.");

                return self::FAILURE;
            }

            // Um alvo explícito ignora o filtro de status: a intenção de
            // corrigir esta PI específica já foi declarada pelo operador.
            $query->where('proforma_invoice_id', $pi->id);
        } elseif (! $this->option('all-statuses')) {
            $query->whereHas('proformaInvoice', function ($q) {
                $q->where('status', ProformaInvoiceStatus::DRAFT);
            });
        }

        $updated = 0;

        $query->chunkById(200, function ($items) use (&$updated, $isDryRun) {
            foreach ($items as $item) {
                $this->line(sprintf(
                    '%s [%s] item #%d: "%s" -> "%s"',
                    $item->proformaInvoice?->reference ?? 'PI?',
                    $item->proformaInvoice?->status?->value ?? '?',
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

    protected function resolvePi(string $idOrReference): ?ProformaInvoice
    {
        return ProformaInvoice::query()
            ->when(
                ctype_digit($idOrReference),
                fn ($q) => $q->whereKey((int) $idOrReference),
                fn ($q) => $q->where('reference', $idOrReference),
            )
            ->first();
    }
}
