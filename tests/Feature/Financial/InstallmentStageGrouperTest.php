<?php

namespace Tests\Feature\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\InstallmentStageGrouper;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agrupador de parcelas iguais (estágio + lado + moeda), a mesma regra da
 * aba do embarque, reutilizável fora de tabelas Filament (portal do cliente).
 */
class InstallmentStageGrouperTest extends TestCase
{
    use RefreshDatabase;

    private function installment(ProformaInvoice $pi, string $label, CalculationBase $due, int $pct, int $amount, string $currency = 'USD'): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => $label.' — [SH-1 / '.$pi->reference.']',
            'percentage' => $pct,
            'amount' => $amount,
            'currency_code' => $currency,
            'due_condition' => $due,
            'is_credit' => false,
        ]);
    }

    public function test_equal_installments_are_summed_and_keep_their_items(): void
    {
        $a = ProformaInvoice::factory()->create();
        $b = ProformaInvoice::factory()->create();

        $first = $this->installment($a, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 437_375_000);
        $second = $this->installment($b, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 712_417_100);
        $other = $this->installment($a, '60% — Delivery Date', CalculationBase::DELIVERY_DATE, 60, 874_750_000);

        // 10.000,00 pagos na primeira parcela.
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND, 'company_id' => $a->company_id, 'amount' => 100_000_000,
            'currency_code' => 'USD', 'payment_date' => '2026-09-18', 'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id, 'payment_schedule_item_id' => $first->id, 'allocated_amount' => 100_000_000,
            'exchange_rate' => null, 'allocated_amount_in_document_currency' => 100_000_000,
        ]);

        $groups = InstallmentStageGrouper::group(collect([$first->fresh(), $other->fresh(), $second->fresh()]));

        $this->assertCount(2, $groups);

        $thirty = $groups->first();
        $this->assertSame('30% — Before Shipment', $thirty['title']);
        $this->assertSame('USD', $thirty['currency']);
        $this->assertSame(2, $thirty['count']);
        $this->assertSame(1_149_792_100, $thirty['amount']);
        $this->assertSame(100_000_000, $thirty['paid']);
        $this->assertSame(1_049_792_100, $thirty['remaining']);
        $this->assertSame([$first->id, $second->id], $thirty['items']->pluck('id')->all());

        // Ordem de primeira aparição é preservada (a lista vem por vencimento).
        $this->assertSame('60% — Delivery Date', $groups->last()['title']);
    }

    public function test_currency_and_additional_costs_never_mix_with_a_stage(): void
    {
        $pi = ProformaInvoice::factory()->create();

        $usd = $this->installment($pi, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 100_000_000);
        $eur = $this->installment($pi, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 100_000_000, 'EUR');
        $freight = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class, 'payable_id' => $pi->id, 'label' => 'Freight: Sea Freight',
            'percentage' => 0, 'amount' => 328_000_000, 'currency_code' => 'USD', 'due_condition' => null,
            'source_type' => AdditionalCost::class, 'source_id' => 1, 'is_credit' => false,
        ]);

        $groups = InstallmentStageGrouper::group(collect([$usd, $eur, $freight]));

        $this->assertCount(3, $groups);
        $this->assertSame(['client', 'client', 'cost_in'], $groups->pluck('bucket')->all());
        $this->assertSame(['USD', 'EUR', 'USD'], $groups->pluck('currency')->all());
        // Custos não têm estágio: o título fica por conta de quem exibe.
        $this->assertNull($groups->last()['title']);
    }
}
