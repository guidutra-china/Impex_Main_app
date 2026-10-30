<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyLastSalePricesCommand extends Command
{
    protected $signature = 'proforma-invoices:apply-last-sale-prices
        {pi : Proforma invoice id or reference (DRAFT only)}
        {--dry-run : Do not persist changes}
        {--overwrite : Also replace prices already filled in}';

    protected $description = 'Fill a DRAFT proforma invoice with the last sale price of each product to the same client (previous PIs, then the client product link)';

    public function handle(): int
    {
        $pi = $this->resolvePi((string) $this->argument('pi'));

        if ($pi === null) {
            $this->error("Proforma invoice '{$this->argument('pi')}' not found.");

            return self::FAILURE;
        }

        // Preço de PI já enviada/confirmada alimenta cronograma e PO: só rascunho.
        if ($pi->status !== ProformaInvoiceStatus::DRAFT) {
            $this->error("{$pi->reference} is {$pi->status->value}; only DRAFT proforma invoices can be repriced.");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        $rows = [];
        $changes = [];

        foreach ($pi->items()->with('product')->orderBy('sort_order')->orderBy('id')->get() as $item) {
            $sku = $item->product?->sku ?? '—';

            if ($item->product_id === null) {
                $rows[] = [$item->id, $sku, $this->money($item->unit_price), '', 'sem produto'];

                continue;
            }

            if ($item->unit_price > 0 && ! $overwrite) {
                $rows[] = [$item->id, $sku, $this->money($item->unit_price), '', 'mantido (já tem preço)'];

                continue;
            }

            [$price, $source] = $this->lastSalePrice($pi, $item);

            if ($price === null) {
                $rows[] = [$item->id, $sku, $this->money($item->unit_price), '', 'sem histórico de venda'];

                continue;
            }

            $rows[] = [$item->id, $sku, $this->money($item->unit_price), $this->money($price), $source];

            if ($price !== $item->unit_price) {
                $changes[$item->id] = $price;
            }
        }

        $this->table(['Item', 'SKU', 'Atual', 'Último preço', 'Origem'], $rows);

        if (! $isDryRun && $changes !== []) {
            DB::transaction(function () use ($pi, $changes) {
                $pi->items()->whereKey(array_keys($changes))->get()
                    ->each(fn (ProformaInvoiceItem $item) => $item->update(['unit_price' => $changes[$item->id]]));
            });
        }

        $this->info(sprintf(
            '%s%s: %d item(s) repriced.',
            $isDryRun ? '[DRY RUN] ' : '',
            $pi->reference,
            count($changes),
        ));

        return self::SUCCESS;
    }

    /**
     * Última venda do produto ao mesmo cliente, na mesma moeda: primeiro a PI
     * mais recente (cancelada/excluída não conta), depois o preço do vínculo
     * cliente↔produto (company_product.unit_price), que guarda a última venda
     * de antes do sistema.
     *
     * @return array{0: ?int, 1: string}
     */
    protected function lastSalePrice(ProformaInvoice $pi, ProformaInvoiceItem $item): array
    {
        $previous = ProformaInvoiceItem::query()
            ->select('proforma_invoice_items.unit_price', 'proforma_invoices.reference')
            ->join('proforma_invoices', 'proforma_invoices.id', '=', 'proforma_invoice_items.proforma_invoice_id')
            ->whereNull('proforma_invoices.deleted_at')
            ->where('proforma_invoices.id', '!=', $pi->id)
            ->where('proforma_invoices.company_id', $pi->company_id)
            ->where('proforma_invoices.currency_code', $pi->currency_code)
            ->where('proforma_invoices.status', '!=', ProformaInvoiceStatus::CANCELLED->value)
            ->where('proforma_invoice_items.product_id', $item->product_id)
            ->where('proforma_invoice_items.unit_price', '>', 0)
            ->orderByDesc('proforma_invoices.issue_date')
            ->orderByDesc('proforma_invoices.id')
            ->orderByDesc('proforma_invoice_items.id')
            ->first();

        if ($previous !== null) {
            return [(int) $previous->unit_price, $previous->reference];
        }

        $link = CompanyProduct::query()
            ->where('company_id', $pi->company_id)
            ->where('product_id', $item->product_id)
            ->where('role', 'client')
            ->where('unit_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('currency_code')->orWhere('currency_code', $pi->currency_code))
            ->first();

        if ($link !== null) {
            return [$link->unit_price, 'cadastro do cliente'];
        }

        return [null, ''];
    }

    protected function money(int $minor): string
    {
        return number_format(Money::toMajor($minor), 4, '.', '');
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
