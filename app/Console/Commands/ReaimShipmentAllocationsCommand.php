<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Financial\Actions\ReconcileSettlementStateAction;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Passa as alocações de pagamento de uma parcela por embarque para a parcela do
 * embarque que de fato leva a carga.
 *
 * Caso real (PO-2026-00033): os 70% foram pagos na parcela
 * `[SH-2026-00021 / PO-2026-00033]`, mas os itens da PO acabaram embarcando no
 * SH-2026-00020. O sistema passou a agendar os 70% de novo no SH-20 e a PO fica
 * com a parcela paga no embarque errado mais uma pendente no certo, e o
 * `financial:audit-stale-schedules --fix` não resolve porque a parcela paga não
 * pode ser removida.
 *
 * O comando só age quando os dois lados são inequívocos: as duas parcelas são do
 * mesmo documento (PI/PO), mesma etapa e moeda, são parcelas por embarque
 * distintas, o embarque de origem não leva mais nenhum item do documento, o de
 * destino leva, e as alocações cabem no valor do destino. Atualiza pelo model e
 * reconcilia os dois lados (o observer só dispara em created/deleted).
 *
 * Dry-run por padrão; passe --apply para gravar. Depois de aplicar, rode
 * `financial:audit-stale-schedules --fix` para o gerador descartar a parcela
 * vazia do embarque de origem.
 */
class ReaimShipmentAllocationsCommand extends Command
{
    protected $signature = 'financial:reaim-shipment-allocations
        {from : Id da parcela por embarque que recebeu o pagamento}
        {to : Id da parcela por embarque que deve receber o pagamento}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Passa as alocações de pagamento de uma parcela por embarque para a do embarque que leva a carga';

    public function handle(ReconcileSettlementStateAction $reconcile): int
    {
        try {
            $from = $this->parcel((int) $this->argument('from'));
            $to = $this->parcel((int) $this->argument('to'));
            $allocations = $this->guard($from, $to);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Origem:  #%d %s (%s, %s)', $from->id, $from->label, $from->status->value, $this->money((int) $from->amount)));
        $this->line(sprintf('Destino: #%d %s (%s, %s)', $to->id, $to->label, $to->status->value, $this->money((int) $to->amount)));

        $this->table(
            ['Alocação', 'Pagamento', 'Valor (moeda do documento)'],
            $allocations->map(fn (PaymentAllocation $a) => [
                $a->id,
                $a->payment?->number ?? '#'.$a->payment_id,
                $this->money((int) $a->allocated_amount_in_document_currency),
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($allocations, $from, $to, $reconcile) {
            foreach ($allocations as $allocation) {
                $allocation->update(['payment_schedule_item_id' => $to->id]);
                $reconcile->forAllocation($allocation->refresh());
            }

            $from->refresh()->recalculateStatus();
            $to->refresh()->recalculateStatus();
        });

        $this->info(sprintf('✓ %d alocação(ões) passadas de #%d para #%d.', $allocations->count(), $from->id, $to->id));
        $this->line('Agora rode: php artisan financial:audit-stale-schedules --fix (só deve restar este documento).');

        return self::SUCCESS;
    }

    private function parcel(int $id): PaymentScheduleItem
    {
        $parcel = PaymentScheduleItem::query()->find($id);

        if (! $parcel) {
            throw new RuntimeException("Parcela #{$id} não encontrada.");
        }

        return $parcel;
    }

    /**
     * @return \Illuminate\Support\Collection<int, PaymentAllocation>
     */
    private function guard(PaymentScheduleItem $from, PaymentScheduleItem $to)
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('Origem e destino são a mesma parcela.');
        }

        if ($from->payable_type !== $to->payable_type || $from->payable_id !== $to->payable_id) {
            throw new RuntimeException('As parcelas são de documentos diferentes.');
        }

        if (! in_array($from->payable_type, [ProformaInvoice::class, PurchaseOrder::class], true)) {
            throw new RuntimeException('Só vale para parcelas de PI ou PO.');
        }

        if ($from->is_credit || $to->is_credit) {
            throw new RuntimeException('Parcelas de crédito não são aceitas.');
        }

        if ($from->shipment_id === null || $to->shipment_id === null || $from->shipment_id === $to->shipment_id) {
            throw new RuntimeException('Ambas precisam ser parcelas de embarques diferentes.');
        }

        if ($from->payment_term_stage_id !== $to->payment_term_stage_id) {
            throw new RuntimeException('As parcelas são de etapas diferentes do termo de pagamento.');
        }

        if ($from->currency_code !== $to->currency_code) {
            throw new RuntimeException('As parcelas têm moedas diferentes.');
        }

        if ($this->itemsOnShipment($from, (int) $from->shipment_id) > 0) {
            throw new RuntimeException('O embarque de origem ainda leva itens deste documento; o pagamento está no lugar certo.');
        }

        if ($this->itemsOnShipment($to, (int) $to->shipment_id) === 0) {
            throw new RuntimeException('O embarque de destino não leva nenhum item deste documento.');
        }

        if ($to->allocations()->exists()) {
            throw new RuntimeException('O destino já tem alocações; não vou misturar.');
        }

        $allocations = PaymentAllocation::query()
            ->with('payment:id,number')
            ->where('payment_schedule_item_id', $from->id)
            ->orderBy('id')
            ->get();

        if ($allocations->isEmpty()) {
            throw new RuntimeException('A parcela de origem não tem alocações.');
        }

        if ($allocations->sum('allocated_amount_in_document_currency') > (int) $to->amount) {
            throw new RuntimeException('As alocações passam do valor da parcela de destino.');
        }

        return $allocations;
    }

    private function itemsOnShipment(PaymentScheduleItem $parcel, int $shipmentId): int
    {
        $query = ShipmentItem::query()->where('shipment_id', $shipmentId);

        return $parcel->payable_type === PurchaseOrder::class
            ? $query->whereHas('purchaseOrderItem', fn ($q) => $q->where('purchase_order_id', $parcel->payable_id))->count()
            : $query->whereHas('proformaInvoiceItem', fn ($q) => $q->where('proforma_invoice_id', $parcel->payable_id))->count();
    }

    private function money(int $scaled): string
    {
        return number_format($scaled / 10000, 2);
    }
}
