<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Exceptions\ShipmentDeletionBlockedException;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apagar um embarque não removia nada do cronograma: as parcelas ship-specific
 * continuavam vivas apontando para um embarque inexistente (caso SH-2026-00033,
 * que deixou US$ 108.360 na PI-49 e uma pendência de US$ 582.998 na PO-57).
 * O `cascadeOnDelete` da FK não ajuda porque soft delete é UPDATE, não DELETE.
 *
 * Regra: parcela sem dinheiro é pura projeção do embarque e vai junto; parcela
 * com pagamento alocado bloqueia a exclusão, porque re-alocar dinheiro é
 * decisão de quem opera, não efeito colateral de apagar um embarque.
 */
class ShipmentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
        ]);
        $this->shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);
    }

    private function shipSpecificItem(PaymentScheduleStatus $status = PaymentScheduleStatus::PENDING): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'shipment_id' => $this->shipment->id,
            'label' => '70% — Before Shipment — ['.$this->shipment->reference.' / '.$this->pi->reference.']',
            'percentage' => 70,
            'amount' => 100000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
            'status' => $status,
            'sort_order' => 2,
        ]);
    }

    private function mirrorItem(PaymentScheduleStatus $status = PaymentScheduleStatus::PENDING): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'shipment_id' => $this->shipment->id,
            'label' => '70% — Before Shipment — ['.$this->shipment->reference.' / '.$this->pi->reference.']',
            'percentage' => 70,
            'amount' => 100000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
            'status' => $status,
            'sort_order' => 2,
        ]);
    }

    private function allocate(PaymentScheduleItem $psi, int $amount = 50000): PaymentAllocation
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-01',
            'reference' => 'PAY-TEST-'.$psi->id,
            'status' => PaymentStatus::APPROVED,
        ]);

        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    public function test_shipment_without_schedule_items_deletes_normally(): void
    {
        $this->shipment->delete();

        $this->assertSoftDeleted('shipments', ['id' => $this->shipment->id]);
    }

    public function test_deleting_removes_the_empty_ship_specific_item(): void
    {
        $psi = $this->shipSpecificItem();

        $this->shipment->delete();

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $psi->id]);
    }

    public function test_deleting_removes_the_shipment_mirror(): void
    {
        $mirror = $this->mirrorItem();

        $this->shipment->delete();

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $mirror->id]);
    }

    public function test_a_paid_mirror_does_not_block_because_the_money_lives_on_the_document(): void
    {
        // O espelho é reflexo: getMirrorPaidAmount() deriva o pago do canônico
        // da PI/PO. Quem tem que barrar é o canônico, não o reflexo.
        $mirror = $this->mirrorItem(PaymentScheduleStatus::PAID);

        $this->shipment->delete();

        $this->assertSoftDeleted('shipments', ['id' => $this->shipment->id]);
        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $mirror->id]);
    }

    public function test_paid_ship_specific_item_blocks_the_deletion(): void
    {
        $psi = $this->shipSpecificItem(PaymentScheduleStatus::PAID);

        $this->expectException(ShipmentDeletionBlockedException::class);

        try {
            $this->shipment->delete();
        } finally {
            $this->assertNotSoftDeleted('shipments', ['id' => $this->shipment->id]);
            $this->assertDatabaseHas('payment_schedule_items', ['id' => $psi->id]);
        }
    }

    public function test_allocated_ship_specific_item_blocks_even_when_pending(): void
    {
        $psi = $this->shipSpecificItem();
        $this->allocate($psi);

        $this->expectException(ShipmentDeletionBlockedException::class);

        $this->shipment->delete();
    }

    public function test_mirror_with_its_own_allocation_blocks(): void
    {
        $mirror = $this->mirrorItem();
        $this->allocate($mirror);

        $this->expectException(ShipmentDeletionBlockedException::class);

        $this->shipment->delete();
    }

    public function test_blocked_deletion_does_not_remove_the_sibling_empty_items(): void
    {
        $empty = $this->shipSpecificItem();
        $paid = $this->shipSpecificItem(PaymentScheduleStatus::PAID);

        try {
            $this->shipment->delete();
            $this->fail('A exclusão deveria ter sido bloqueada.');
        } catch (ShipmentDeletionBlockedException) {
            // Tudo ou nada: a guarda roda antes de qualquer remoção.
        }

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $empty->id]);
        $this->assertDatabaseHas('payment_schedule_items', ['id' => $paid->id]);
    }

    public function test_the_exception_names_the_offending_items(): void
    {
        $psi = $this->shipSpecificItem(PaymentScheduleStatus::PAID);

        try {
            $this->shipment->delete();
            $this->fail('A exclusão deveria ter sido bloqueada.');
        } catch (ShipmentDeletionBlockedException $e) {
            $this->assertCount(1, $e->blockers);
            $this->assertStringContainsString($this->pi->reference, $e->blockers[0]);
        }
    }

    public function test_get_deletion_blockers_is_empty_when_nothing_holds_money(): void
    {
        $this->shipSpecificItem();
        $this->mirrorItem();

        $this->assertSame([], $this->shipment->getDeletionBlockers());
    }

    public function test_items_of_other_shipments_are_untouched(): void
    {
        $other = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
        ]);
        $otherPsi = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'shipment_id' => $other->id,
            'label' => '70% — Before Shipment — ['.$other->reference.' / '.$this->pi->reference.']',
            'percentage' => 70,
            'amount' => 100000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 2,
        ]);

        $this->shipSpecificItem();
        $this->shipment->delete();

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $otherPsi->id]);
    }

    public function test_base_items_without_a_shipment_are_untouched(): void
    {
        $base = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'shipment_id' => null,
            'label' => '30% — Order Date',
            'percentage' => 30,
            'amount' => 50000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE->value,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 1,
        ]);

        $this->shipSpecificItem();
        $this->shipment->delete();

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $base->id]);
    }
}
