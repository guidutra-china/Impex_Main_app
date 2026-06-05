<?php

namespace App\Console\Commands;

use App\Domain\Inquiries\Actions\AdvanceInquiryToQuotedAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Operations\OperationsPipeline;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class ReconcileConfirmedProformaInvoices extends Command
{
    protected $signature = 'operations:reconcile-confirmed-pis
        {--dry-run : Report what would change without applying}';

    protected $description = 'Backfill downstream status advances (inquiry→won, SQs→selected, quotation→approved)
        for ProformaInvoices that were confirmed before the auto-advance code existed.
        Safe to run multiple times — idempotent via canTransitionTo guards.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode — no changes will be written.');
            $this->newLine();
        }

        // Query all "past confirmed" statuses — those that have crossed the CONFIRMED threshold.
        $statuses = [
            ProformaInvoiceStatus::CONFIRMED->value,
            ProformaInvoiceStatus::SHIPPED->value,
            ProformaInvoiceStatus::FINALIZED->value,
            ProformaInvoiceStatus::REOPENED->value,
        ];

        $scanned = 0;
        $advanced = 0;
        $skipped = 0;
        $failed = 0;

        // chunkById bounds memory on large production sets. Safe here: reconciliation
        // mutates each PI's downstream records (inquiry/SQs/quotation), never the PI's
        // own status or id, so the paged cursor stays stable.
        ProformaInvoice::query()
            ->whereIn('status', $statuses)
            ->with('inquiry')
            ->chunkById(200, function ($pis) use ($dryRun, &$scanned, &$advanced, &$skipped, &$failed) {
                foreach ($pis as $pi) {
                    /** @var ProformaInvoice $pi */
                    $scanned++;
                    $this->line("PI {$pi->reference} (status: {$pi->status->value}):");

                    if ($dryRun) {
                        [$wouldAdvance, $wouldSkip] = $this->dryRunForPi($pi);
                        $advanced += $wouldAdvance;
                        $skipped += $wouldSkip;
                    } else {
                        [$didAdvance, $didSkip, $didFail] = $this->applyForPi($pi);
                        $advanced += $didAdvance;
                        $skipped += $didSkip;
                        $failed += $didFail;
                    }
                }
            });

        $this->newLine();

        if ($dryRun) {
            $this->info("DRY-RUN summary — scanned: {$scanned}, would advance: {$advanced}, already in place: {$skipped}");
        } else {
            $this->info("Summary — scanned: {$scanned}, advanced: {$advanced}, skipped (already in place): {$skipped}, failed: {$failed}");
        }

        return self::SUCCESS;
    }

    /**
     * Dry-run preview for one PI: read-only checks, no writes.
     * Returns [wouldAdvance, wouldSkip].
     */
    private function dryRunForPi(ProformaInvoice $pi): array
    {
        $wouldAdvance = 0;
        $wouldSkip = 0;

        $inquiry = $pi->inquiry;

        // Step 1 preview — inquiry toward WON.
        if ($inquiry) {
            $inquiryStatuses = [
                InquiryStatus::RECEIVED->value,
                InquiryStatus::QUOTING->value,
                InquiryStatus::QUOTED->value,
            ];

            if (in_array($inquiry->getCurrentStatus(), $inquiryStatuses, true)) {
                $this->line("  → inquiry {$inquiry->reference} ({$inquiry->status->value}) would advance toward won");
                $wouldAdvance++;
            } else {
                $this->line("  ~ inquiry {$inquiry->reference} ({$inquiry->status->value}) already at or past won — skip");
                $wouldSkip++;
            }
        } else {
            $this->line('  ~ no inquiry linked — skip');
            $wouldSkip++;
        }

        // Step 2 preview — SQs.
        if ($inquiry) {
            $sqs = $inquiry->supplierQuotations;
            foreach ($sqs as $sq) {
                if ($sq->canTransitionTo('selected')) {
                    $this->line("  → SQ {$sq->reference} ({$sq->status->value}) would advance to selected");
                    $wouldAdvance++;
                } else {
                    $this->line("  ~ SQ {$sq->reference} ({$sq->status->value}) cannot transition to selected — skip");
                    $wouldSkip++;
                }
            }

            if ($sqs->isEmpty()) {
                $this->line('  ~ no supplier quotations — skip');
            }

            // Step 2 preview — latest quotation.
            $latestQuotation = $inquiry->quotations()->latest('version')->first();
            if ($latestQuotation) {
                if ($latestQuotation->canTransitionTo('approved')) {
                    $this->line("  → Quotation {$latestQuotation->reference} v{$latestQuotation->version} ({$latestQuotation->status->value}) would advance to approved");
                    $wouldAdvance++;
                } else {
                    $this->line("  ~ Quotation {$latestQuotation->reference} v{$latestQuotation->version} ({$latestQuotation->status->value}) cannot transition to approved — skip");
                    $wouldSkip++;
                }
            } else {
                $this->line('  ~ no quotations — skip');
            }
        }

        return [$wouldAdvance, $wouldSkip];
    }

    /**
     * Apply reconciliation for one PI.
     * Returns [advanced, skipped, failed].
     */
    private function applyForPi(ProformaInvoice $pi): array
    {
        $advanced = 0;
        $skipped = 0;
        $failed = 0;

        // Step 1: Walk inquiry to QUOTED so that the CONFIRMED auto-advance rules can reach WON.
        // Capture the inquiry instance first so we pass the same object to the action (it refreshes in place).
        $inquiry = $pi->inquiry;

        if ($inquiry) {
            app(AdvanceInquiryToQuotedAction::class)->execute($inquiry);
        }

        // Unset cached relation so step 2 resolvers get the refreshed inquiry from DB.
        $pi->unsetRelation('inquiry');

        // Step 2: Replay the declared CONFIRMED auto-advance rules.
        $advances = OperationsPipeline::autoAdvancesFor($pi, ProformaInvoiceStatus::CONFIRMED->value);

        foreach ($advances as $advance) {
            $resolved = rescue(fn () => ($advance->resolveTargets)($pi), null);

            // Normalize to array of models.
            if ($resolved === null) {
                $targets = [];
            } elseif ($resolved instanceof Model) {
                $targets = [$resolved];
            } else {
                // Eloquent Collection or other iterable.
                $targets = collect($resolved)->all();
            }

            foreach ($targets as $target) {
                if (! $target instanceof Model) {
                    continue;
                }

                if ($target->canTransitionTo($advance->targetStatus)) {
                    try {
                        $target->transitionTo(
                            $advance->targetStatus,
                            notes: "Backfill: PI {$pi->reference} confirmed before auto-advance existed"
                        );
                        $this->line("  advanced {$this->modelLabel($target)} → {$advance->targetStatus}");
                        $advanced++;
                    } catch (\Throwable $e) {
                        $this->warn("  FAILED {$this->modelLabel($target)} → {$advance->targetStatus}: {$e->getMessage()}");
                        report($e);
                        $failed++;
                    }
                } else {
                    $this->line("  ~ {$this->modelLabel($target)} already at {$target->getCurrentStatus()} — skip");
                    $skipped++;
                }
            }
        }

        return [$advanced, $skipped, $failed];
    }

    /**
     * Returns a short human-readable label for a model instance (class + reference/id).
     */
    private function modelLabel(Model $model): string
    {
        $class = class_basename($model);
        $label = $model->getAttribute('reference') ?? "#{$model->getKey()}";

        return "{$class} {$label}";
    }
}
