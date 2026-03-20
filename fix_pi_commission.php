<?php

/**
 * Fix PI items where commission was incorrectly embedded in unit_price
 * when it should have been separate.
 *
 * For PIs linked to quotations with commission_type = 'separate',
 * sets unit_price = unit_cost on all items.
 *
 * Usage: php artisan tinker fix_pi_commission.php
 * Or:    php fix_pi_commission.php (with bootstrap)
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->handleRequest(\Illuminate\Http\Request::capture());

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Models\AdditionalCost;
use Illuminate\Support\Facades\DB;

$references = ['PI-2026-00006', 'PI-2026-00007'];

foreach ($references as $ref) {
    $pi = ProformaInvoice::where('reference', $ref)->first();

    if (! $pi) {
        echo "❌ {$ref}: not found\n";
        continue;
    }

    // Find linked quotation with separate commission
    $quotation = $pi->quotations()
        ->where('commission_type', CommissionType::SEPARATE->value)
        ->first();

    if (! $quotation) {
        echo "⚠️  {$ref}: no linked quotation with SEPARATE commission — skipping\n";
        continue;
    }

    $commissionRate = (float) $quotation->commission_rate;
    echo "\n📋 {$ref} (ID: {$pi->id})\n";
    echo "   Quotation: {$quotation->reference} — commission: {$commissionRate}%\n";

    DB::transaction(function () use ($pi, $quotation, $commissionRate, $ref) {
        $items = $pi->items()->get();
        $oldTotal = 0;
        $newTotal = 0;
        $updated = 0;

        foreach ($items as $item) {
            $oldPrice = $item->unit_price;
            $newPrice = $item->unit_cost;

            if ($oldPrice === $newPrice) {
                echo "   ✓ Item #{$item->sort_order}: already correct (unit_price = unit_cost = {$newPrice})\n";
                $oldTotal += $oldPrice * $item->quantity;
                $newTotal += $newPrice * $item->quantity;
                continue;
            }

            $oldLineTotal = $oldPrice * $item->quantity;
            $newLineTotal = $newPrice * $item->quantity;
            $oldTotal += $oldLineTotal;
            $newTotal += $newLineTotal;

            $oldDisplay = number_format($oldPrice / 10000, 4);
            $newDisplay = number_format($newPrice / 10000, 4);

            echo "   → Item #{$item->sort_order} ({$item->description}): \${$oldDisplay} → \${$newDisplay}\n";

            ProformaInvoiceItem::where('id', $item->id)->update(['unit_price' => $newPrice]);
            $updated++;
        }

        echo "   Updated: {$updated} items\n";
        echo "   Old total: \$" . number_format($oldTotal / 10000, 2) . "\n";
        echo "   New total: \$" . number_format($newTotal / 10000, 2) . "\n";

        // Check if commission AdditionalCost exists and fix its amount
        $commissionCost = AdditionalCost::where('costable_type', $pi->getMorphClass())
            ->where('costable_id', $pi->id)
            ->where('cost_type', AdditionalCostType::COMMISSION)
            ->first();

        if ($commissionCost) {
            $correctCommission = (int) round($newTotal * ($commissionRate / 100));
            $oldCommission = $commissionCost->amount;

            if ($oldCommission !== $correctCommission) {
                AdditionalCost::where('id', $commissionCost->id)->update([
                    'amount' => $correctCommission,
                    'amount_in_document_currency' => $correctCommission,
                ]);
                echo "   Commission cost fixed: \$" . number_format($oldCommission / 10000, 2) . " → \$" . number_format($correctCommission / 10000, 2) . "\n";
            } else {
                echo "   Commission cost already correct: \$" . number_format($correctCommission / 10000, 2) . "\n";
            }
        } else {
            // Create missing commission cost
            $commissionAmount = (int) round($newTotal * ($commissionRate / 100));
            AdditionalCost::create([
                'costable_type' => $pi->getMorphClass(),
                'costable_id' => $pi->id,
                'cost_type' => AdditionalCostType::COMMISSION,
                'description' => 'Service Fee (' . $commissionRate . '%) — ' . $quotation->reference,
                'amount' => $commissionAmount,
                'currency_code' => $pi->currency_code,
                'exchange_rate' => 1,
                'amount_in_document_currency' => $commissionAmount,
                'billable_to' => BillableTo::CLIENT,
                'cost_date' => now()->toDateString(),
                'status' => AdditionalCostStatus::PENDING,
                'notes' => 'Auto-generated fix from ' . $quotation->reference,
            ]);
            echo "   Commission cost CREATED: \$" . number_format($commissionAmount / 10000, 2) . "\n";
        }

        echo "   ✅ {$ref} fixed!\n";
    });
}

echo "\n🏁 Done. Now regenerate the payment schedule for each PI via the UI.\n";
