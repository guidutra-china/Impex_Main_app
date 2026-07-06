<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Console\Command;

/**
 * Re-links shipment items whose purchase_order_item_id was nulled.
 *
 * shipment_items.purchase_order_item_id is `on delete set null`, so editing or
 * regenerating a PO after a shipment already exists deletes the old PO line and
 * silently drops the shipment's link to it. The shipment stays correctly linked
 * to its PI item, so the fulfillment "debit" against the PI is unaffected — but
 * the PO-side "how much of this PO shipped" tracking is understated.
 *
 * This command relinks each orphaned shipment item to the PO line that now
 * carries the same proforma_invoice_item_id. It only acts when EXACTLY ONE PO
 * line matches (unambiguous); items with no matching PO line (never ordered via
 * a PO) or with multiple candidates are reported and left untouched.
 *
 * Dry-run by default; pass --apply to write.
 */
class RelinkShipmentPoItemsCommand extends Command
{
    protected $signature = 'shipments:relink-po-items {--apply : Persist the changes (otherwise dry-run)}';

    protected $description = 'Re-link shipment items to their PO line when the purchase_order_item_id link was lost';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Map: proforma_invoice_item_id => collection of PO line ids.
        $poByPiItem = PurchaseOrderItem::query()
            ->whereNotNull('proforma_invoice_item_id')
            ->get(['id', 'proforma_invoice_item_id'])
            ->groupBy('proforma_invoice_item_id');

        $orphans = ShipmentItem::query()
            ->whereNull('purchase_order_item_id')
            ->whereNotNull('proforma_invoice_item_id')
            ->with(['shipment:id,reference', 'proformaInvoiceItem:id,description,product_id', 'proformaInvoiceItem.product:id,name'])
            ->orderBy('shipment_id')
            ->get();

        $relinkable = [];
        $ambiguous = 0;
        $noPo = 0;

        foreach ($orphans as $item) {
            $candidates = $poByPiItem->get($item->proforma_invoice_item_id);

            if ($candidates === null || $candidates->isEmpty()) {
                $noPo++;

                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous++;

                continue;
            }

            $relinkable[] = [$item, (int) $candidates->first()->id];
        }

        $this->info(sprintf(
            'Órfãos (shipment item sem PO link): %d — re-vinculáveis: %d | sem PO existente: %d | ambíguos: %d',
            $orphans->count(),
            count($relinkable),
            $noPo,
            $ambiguous,
        ));

        if ($relinkable === []) {
            $this->info('Nada a re-vincular.');

            return self::SUCCESS;
        }

        $this->table(
            ['Shipment', 'Ship item', 'Produto', 'PI item', '→ PO item'],
            collect($relinkable)->map(fn (array $row) => [
                $row[0]->shipment?->reference ?? '—',
                $row[0]->id,
                mb_strimwidth($row[0]->proformaInvoiceItem?->product?->name ?? $row[0]->proformaInvoiceItem?->description ?? '—', 0, 40, '…'),
                $row[0]->proforma_invoice_item_id,
                $row[1],
            ])->all(),
        );

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($relinkable as [$item, $poItemId]) {
            $item->purchase_order_item_id = $poItemId;
            $item->save();
            $updated++;
        }

        $this->info("✓ Re-vinculados: {$updated} shipment items.");

        return self::SUCCESS;
    }
}
