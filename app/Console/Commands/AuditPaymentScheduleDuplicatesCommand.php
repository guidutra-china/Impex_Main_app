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
 * (shipment_id null) que recebeu pagamento/alocação ANTES de o shipment existir
 * e que coexiste com um item ship-specific do MESMO stage. Nesse estado a mesma
 * parcela aparece duas vezes no relatório AP/AR (paga no [remaining] e pendente
 * no ship-specific).
 *
 * Não altera nada. Retorna exit code != 0 quando encontra duplicações, para uso
 * como gate pós-deploy / cron / monitoramento.
 */
class AuditPaymentScheduleDuplicatesCommand extends Command
{
    protected $signature = 'financial:audit-psi-duplicates
                            {--document= : Limita a um documento (ex.: PI-2026-00014 ou PO-2026-00009)}';

    protected $description = 'Auditoria read-only: detecta parcelas [remaining] pagas duplicadas com um item ship-specific do mesmo stage (contagem dupla AP/AR). Não altera dados; exit != 0 se encontrar.';

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

        foreach ($candidates as $remaining) {
            $docRef = $remaining->payable?->reference;

            if ($docFilter && $docRef !== $docFilter) {
                continue;
            }

            // A [remaining] paid item is only a double-count when a ship-specific
            // sibling exists for the same stage. Without one it is the legitimate
            // pre-shipment canonical and must NOT be flagged.
            $shipSpecifics = PaymentScheduleItem::where('payable_type', $remaining->payable_type)
                ->where('payable_id', $remaining->payable_id)
                ->where('payment_term_stage_id', $remaining->payment_term_stage_id)
                ->whereNotNull('shipment_id')
                ->get();

            if ($shipSpecifics->isEmpty()) {
                continue;
            }

            // Auto-fixable when exactly one ship-specific exists and it carries no
            // allocations (promote can convert [remaining] and drop the empty clone).
            $autoFixable = $shipSpecifics->count() === 1
                && $shipSpecifics->every(fn ($s) => $s->allocations()->doesntExist());

            $findings[] = [
                'psi' => '#'.$remaining->id,
                'document' => $docRef ?? ($remaining->payable_type.'#'.$remaining->payable_id),
                'stage' => (string) $remaining->payment_term_stage_id,
                'paid' => number_format($remaining->amount / 100, 2),
                'ship_specific' => $shipSpecifics->pluck('id')->map(fn ($id) => '#'.$id)->implode(', '),
                'resolution' => $autoFixable ? 'promote (auto)' : 'manual review',
            ];
        }

        if (empty($findings)) {
            $this->info('OK: nenhuma contagem dupla [remaining] + ship-specific encontrada.');

            return self::SUCCESS;
        }

        $this->error(count($findings).' parcela(s) [remaining] paga(s) duplicada(s) com item ship-specific do mesmo stage:');
        $this->newLine();
        $this->table(
            ['PSI', 'Documento', 'Stage', 'Pago', 'Ship-specific', 'Resolução'],
            $findings,
        );
        $this->newLine();
        $this->warn('Revise com:   php artisan financial:promote-remaining-to-shipment --dry-run');
        $this->warn('E aplique com: php artisan financial:promote-remaining-to-shipment');

        return self::FAILURE;
    }
}
