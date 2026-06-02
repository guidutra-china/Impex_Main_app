<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;

/**
 * Read-only production health-check for the "paid before shipment" double-count.
 *
 * Detecta a contagem dupla que o promote fix previne: um canonical [remaining]
 * (shipment_id null) pago/alocado que coexiste com um item ship-specific do
 * MESMO stage E faz o stage somar MAIS do que sua fatia do total do documento.
 *
 * Um embarque parcial legítimo também tem [remaining] + ship-specific, mas os
 * valores se dividem (embarcado + restante) e somam exatamente a fatia do stage
 * — esse caso NÃO é sinalizado. Só super-agendamento (soma > esperado) é dupla.
 *
 * Não altera nada. Retorna exit code != 0 quando encontra duplicações, para uso
 * como gate pós-deploy / cron / monitoramento.
 */
class AuditPaymentScheduleDuplicatesCommand extends Command
{
    protected $signature = 'financial:audit-psi-duplicates
                            {--document= : Limita a um documento (ex.: PI-2026-00014 ou PO-2026-00009)}';

    protected $description = 'Auditoria read-only: detecta stages super-agendados (parcela [remaining] paga + ship-specific somando além da fatia do stage = contagem dupla AP/AR). Embarque parcial legítimo não é sinalizado. Não altera dados; exit != 0 se encontrar.';

    public function handle(): int
    {
        $docFilter = $this->option('document');

        $candidates = PaymentScheduleItem::query()
            ->whereIn('payable_type', [ProformaInvoice::class, PurchaseOrder::class])
            ->whereNull('shipment_id')
            ->whereNull('source_type')
            ->where('label', 'LIKE', '%[remaining]%')
            ->where(function ($q) {
                $q->whereHas('allocations')
                    ->orWhere('status', PaymentScheduleStatus::PAID->value);
            })
            ->with('allocations')
            ->orderBy('id')
            ->get();

        $findings = [];
        $seenStages = [];

        foreach ($candidates as $remaining) {
            $docRef = $remaining->payable?->reference;

            if ($docFilter && $docRef !== $docFilter) {
                continue;
            }

            // Process each document+stage once.
            $stageKey = $remaining->payable_type.'|'.$remaining->payable_id.'|'.$remaining->payment_term_stage_id;
            if (isset($seenStages[$stageKey])) {
                continue;
            }
            $seenStages[$stageKey] = true;

            // All canonical (non additional-cost) PSIs for this document + stage.
            $stageItems = PaymentScheduleItem::where('payable_type', $remaining->payable_type)
                ->where('payable_id', $remaining->payable_id)
                ->where('payment_term_stage_id', $remaining->payment_term_stage_id)
                ->whereNull('source_type')
                ->with('allocations')
                ->get();

            $shipSpecifics = $stageItems->filter(fn ($i) => $i->shipment_id !== null)->values();

            // Without a ship-specific sibling the paid [remaining] is the legitimate
            // pre-shipment canonical — not a double-count.
            if ($shipSpecifics->isEmpty()) {
                continue;
            }

            // Over-scheduling check: a legitimate partial shipment splits the stage
            // value across shipped (ship-specific) + unshipped ([remaining]), summing
            // to exactly the stage's share of the document total. It is only a real
            // double-count when the stage's PSIs sum to MORE than that share.
            $documentTotal = (int) ($remaining->payable?->total ?? 0);
            $verifiable = $documentTotal > 0 && $remaining->percentage !== null;
            $expected = (int) round($documentTotal * ((float) $remaining->percentage / 100));
            $scheduled = (int) $stageItems->sum('amount');
            $tolerance = 100; // 1 currency unit — absorbs per-item percentage rounding

            if ($verifiable && $scheduled <= $expected + $tolerance) {
                continue; // value reconciles → legitimate split, not a double-count
            }

            // Auto-fixable when exactly one ship-specific exists and it carries no
            // allocations (promote converts [remaining] and drops the empty clone).
            $autoFixable = $shipSpecifics->count() === 1
                && $shipSpecifics->every(fn ($s) => $s->allocations->isEmpty());

            $findings[] = [
                'psi' => '#'.$remaining->id,
                'document' => $docRef ?? ($remaining->payable_type.'#'.$remaining->payable_id),
                'stage' => (string) $remaining->payment_term_stage_id,
                'expected' => $verifiable ? number_format($expected / 100, 2) : '?',
                'scheduled' => number_format($scheduled / 100, 2),
                'excess' => $verifiable ? number_format(($scheduled - $expected) / 100, 2) : '?',
                'resolution' => $autoFixable ? 'promote (auto)' : 'manual review',
            ];
        }

        if (empty($findings)) {
            $this->info('OK: nenhuma contagem dupla encontrada (stages não estão super-agendados).');

            return self::SUCCESS;
        }

        $this->error(count($findings).' stage(s) super-agendado(s) — parcela contada em dobro no AP/AR:');
        $this->newLine();
        $this->table(
            ['PSI [remaining]', 'Documento', 'Stage', 'Esperado', 'Agendado', 'Excesso', 'Resolução'],
            $findings,
        );
        $this->newLine();
        $this->warn('Revise com:   php artisan financial:promote-remaining-to-shipment --dry-run');
        $this->warn('E aplique com: php artisan financial:promote-remaining-to-shipment');
        $this->warn('Casos "manual review" (pagamento dividido) precisam de ajuste manual das alocações.');

        return self::FAILURE;
    }
}
