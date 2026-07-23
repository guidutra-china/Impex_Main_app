<?php

namespace App\Console\Commands;

use App\Domain\Financial\Actions\SyncCreditNoteScheduleAction;
use App\Domain\Financial\Actions\SyncDebitNoteScheduleAction;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\DebitNote;
use Illuminate\Console\Command;

class RepairNoteScheduleItemsCommand extends Command
{
    protected $signature = 'financial:repair-note-schedule-items
        {--dry-run : Apenas lista o que seria alterado, sem gravar}';

    protected $description = 'Re-sincroniza os schedule items de Debit/Credit Notes emitidas com seus line items atuais. Repara o dano das edições antigas (delete-recreate de linhas): remove PSIs órfãos sem alocação (avisa nos com alocação) e cria PSIs para linhas emitidas sem item. Caso DN-2026-0006.';

    public function handle(
        SyncDebitNoteScheduleAction $syncDebitNote,
        SyncCreditNoteScheduleAction $syncCreditNote,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $totalChanges = 0;

        $debitNotes = DebitNote::query()
            ->where('status', '!=', DebitNoteStatus::DRAFT->value)
            ->get();

        foreach ($debitNotes as $debitNote) {
            $totalChanges += $this->report($debitNote->reference, $syncDebitNote->execute($debitNote, $dryRun));
        }

        $creditNotes = CreditNote::query()
            ->where('status', '!=', CreditNoteStatus::DRAFT->value)
            ->get();

        foreach ($creditNotes as $creditNote) {
            $totalChanges += $this->report($creditNote->reference, $syncCreditNote->execute($creditNote, $dryRun));
        }

        if ($totalChanges === 0) {
            $this->info('Nenhum schedule item para reparar.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s%d mudança(s) em %d DN(s) + %d CN(s) verificadas.',
            $dryRun ? '[dry-run] ' : '',
            $totalChanges,
            $debitNotes->count(),
            $creditNotes->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $changes
     */
    protected function report(string $reference, array $changes): int
    {
        foreach ($changes as $change) {
            if (str_starts_with($change, 'SKIP')) {
                $this->warn("{$reference}: {$change}");
            } else {
                $this->line("{$reference}: {$change}");
            }
        }

        return count($changes);
    }
}
