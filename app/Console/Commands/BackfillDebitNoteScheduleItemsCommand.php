<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillDebitNoteScheduleItemsCommand extends Command
{
    protected $signature = 'debit-notes:backfill-schedule-items {--dry-run : Only show what would change}';

    protected $description = 'Migrate PaymentScheduleItems from issued Debit Notes to use payable_type=DebitNote, and create any missing ones for line items that were silently skipped under the old code.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $issuedDns = DebitNote::where('status', DebitNoteStatus::ISSUED->value)
            ->with('lineItems')
            ->get();

        $this->info("Found {$issuedDns->count()} issued Debit Notes.");

        $updated = 0;
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($issuedDns, $dryRun, &$updated, &$created, &$skipped) {
            foreach ($issuedDns as $dn) {
                $this->line("DN {$dn->reference} (id={$dn->id}, company_id={$dn->company_id}, lines={$dn->lineItems->count()})");

                foreach ($dn->lineItems as $lineItem) {
                    $existing = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
                        ->where('source_id', $lineItem->id)
                        ->first();

                    if ($existing) {
                        if (
                            $existing->payable_type === DebitNote::class
                            && $existing->payable_id === $dn->id
                        ) {
                            $this->line("  PSI {$existing->id} already on DebitNote — skip.");
                            $skipped++;

                            continue;
                        }

                        $this->line(
                            "  PSI {$existing->id} migrate: "
                            ."{$existing->payable_type}#{$existing->payable_id} "
                            ."→ DebitNote#{$dn->id}"
                        );

                        if (! $dryRun) {
                            $existing->update([
                                'payable_type' => DebitNote::class,
                                'payable_id' => $dn->id,
                                'label' => $dn->reference.': '.$lineItem->description,
                                'due_date' => $dn->due_date,
                            ]);
                        }

                        $updated++;

                        continue;
                    }

                    // No PSI exists — old "silently skipped" path. Create one.
                    $maxSort = PaymentScheduleItem::where('payable_type', DebitNote::class)
                        ->where('payable_id', $dn->id)
                        ->max('sort_order') ?? 0;

                    $this->line("  Create new PSI for line item {$lineItem->id} ({$lineItem->description})");

                    if (! $dryRun) {
                        PaymentScheduleItem::create([
                            'payable_type' => DebitNote::class,
                            'payable_id' => $dn->id,
                            'label' => $dn->reference.': '.$lineItem->description,
                            'percentage' => 0,
                            'amount' => $lineItem->amount,
                            'currency_code' => $lineItem->currency_code,
                            'due_date' => $dn->due_date,
                            'status' => PaymentScheduleStatus::PENDING->value,
                            'is_blocking' => false,
                            'is_credit' => false,
                            'source_type' => DebitNoteLineItem::class,
                            'source_id' => $lineItem->id,
                            'sort_order' => $maxSort + 1,
                        ]);
                    }

                    $created++;
                }
            }
        });

        $this->newLine();
        $this->info("Updated: {$updated}");
        $this->info("Created: {$created}");
        $this->info("Skipped (already correct): {$skipped}");

        if ($dryRun) {
            $this->warn('DRY RUN — nothing was saved. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
