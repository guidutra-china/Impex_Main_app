<?php

namespace App\Console\Commands;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Varredura global de payment schedules desatualizados.
 *
 * Um schedule fica desatualizado quando valores do documento mudam (itens de
 * PI/PO, quantidades embarcadas, payment term) sem que alguém clique em
 * "Regenerate". Este comando compara o total base agendado com o total
 * esperado (HasPaymentSchedule::scheduleStaleness, mesma matemática do
 * ScheduleExpectationCalculator usado pela regeneração) em todos os PIs, POs e
 * Shipments ativos.
 *
 * Sem flags é read-only (relatório + exit code != 0 se encontrar). Com --fix
 * regenera cada documento desatualizado — PIs/POs primeiro, depois Shipments,
 * porque o total esperado do shipment deriva dos preços da PI. Documentos em
 * estado "plan" (itens de Shipment Plan não vinculados a shipment) ou
 * "overridden" (valores com override manual) são listados mas nunca corrigidos
 * automaticamente.
 */
class AuditStaleSchedulesCommand extends Command
{
    protected $signature = 'financial:audit-stale-schedules
                            {--fix : Regenera os schedules desatualizados (PI/PO primeiro, depois Shipments)}
                            {--notify : Envia notificação no painel aos usuários com permissão generate-payment-schedule}';

    protected $description = 'Detecta payment schedules cujo total base diverge dos valores atuais do documento (PI/PO/Shipment). Read-only por padrão; --fix regenera; exit != 0 se encontrar.';

    public function handle(GeneratePaymentScheduleAction $generator): int
    {
        $fix = (bool) $this->option('fix');

        $stale = [];
        $skipped = [];
        $fixed = 0;
        $failed = 0;

        // Fix order matters: shipment expected totals derive from PI item
        // prices, so documents regenerate before shipments.
        $sources = [
            ['PI', ProformaInvoice::query()->active()],
            ['PO', PurchaseOrder::query()->active()],
            ['Shipment', Shipment::query()->whereNot('status', ShipmentStatus::CANCELLED)],
        ];

        foreach ($sources as [$typeLabel, $query]) {
            $query
                ->whereHas('paymentScheduleItems', fn ($q) => $q->whereNotNull('payment_term_stage_id'))
                ->chunkById(100, function ($documents) use (&$stale, &$skipped, &$fixed, &$failed, $typeLabel, $fix, $generator) {
                    foreach ($documents as $document) {
                        $staleness = $document->scheduleStaleness();

                        if (in_array($staleness['state'], ['plan', 'overridden'], true)) {
                            $skipped[] = [
                                'type' => $typeLabel,
                                'document' => $document->reference,
                                'state' => $staleness['state'],
                            ];

                            continue;
                        }

                        if ($staleness['state'] !== 'stale') {
                            continue;
                        }

                        $row = [
                            'type' => $typeLabel,
                            'document' => $document->reference,
                            'scheduled' => number_format($staleness['actual'] / 100, 2),
                            'expected' => number_format($staleness['expected'] / 100, 2),
                            'diff' => number_format($staleness['diff'] / 100, 2),
                            'result' => '—',
                        ];

                        if ($fix) {
                            try {
                                $count = $document instanceof Shipment
                                    ? $generator->regenerateForShipment($document)
                                    : $generator->regenerate($document);

                                $row['result'] = "regenerado ({$count} itens)";
                                $fixed++;
                            } catch (\Throwable $e) {
                                $row['result'] = 'ERRO: '.$e->getMessage();
                                $failed++;

                                Log::error('audit-stale-schedules: regeneration failed', [
                                    'document' => $document->reference,
                                    'exception' => $e,
                                ]);
                            }
                        }

                        $stale[] = $row;
                    }
                });
        }

        if ($fix && $fixed > 0) {
            Cache::forget('operational-alerts:stale-schedules');
        }

        $this->report($stale, $skipped, $fix, $fixed, $failed);

        if ($this->option('notify') && ($stale !== [] || $failed > 0)) {
            $this->notifyAdmins($stale, $fix, $fixed, $failed);
        }

        if ($failed > 0) {
            return self::FAILURE;
        }

        if ($stale !== [] && ! $fix) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, string>>  $stale
     * @param  array<int, array<string, string>>  $skipped
     */
    protected function report(array $stale, array $skipped, bool $fix, int $fixed, int $failed): void
    {
        if ($stale === []) {
            $this->info('OK: nenhum payment schedule desatualizado encontrado.');
        } else {
            $this->error(count($stale).' schedule(s) desatualizado(s):');
            $this->newLine();
            $this->table(
                ['Tipo', 'Documento', 'Agendado', 'Esperado', 'Diferença', $fix ? 'Resultado' : ' '],
                $stale,
            );

            if ($fix) {
                $this->info("Corrigidos: {$fixed}".($failed > 0 ? " | Falhas: {$failed}" : ''));
            } else {
                $this->warn('Read-only. Aplique com: php artisan financial:audit-stale-schedules --fix');
            }
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(count($skipped).' documento(s) pulado(s) — verificar manualmente:');
            $this->table(['Tipo', 'Documento', 'Motivo'], $skipped);
            $this->line('  plan = itens de Shipment Plan sem shipment vinculado (gerido pelo fluxo de planos)');
            $this->line('  overridden = itens com override manual de valor (divergência intencional)');
        }
    }

    /**
     * @param  array<int, array<string, string>>  $stale
     */
    protected function notifyAdmins(array $stale, bool $fix, int $fixed, int $failed): void
    {
        $recipients = User::permission('generate-payment-schedule')->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $lines = collect($stale)
            ->take(10)
            ->map(fn ($row) => "{$row['document']}: {$row['scheduled']} → {$row['expected']}")
            ->implode("\n");

        if (count($stale) > 10) {
            $lines .= "\n+".(count($stale) - 10).'…';
        }

        $title = $fix
            ? __('notifications.stale_schedules.fixed_title', ['count' => $fixed])
            : __('notifications.stale_schedules.found_title', ['count' => count($stale)]);

        $notification = Notification::make()
            ->title($title)
            ->body($lines)
            ->icon('heroicon-o-arrow-path');

        $failed > 0 || ! $fix ? $notification->warning() : $notification->success();

        foreach ($recipients as $user) {
            $notification->sendToDatabase($user);
        }
    }
}
