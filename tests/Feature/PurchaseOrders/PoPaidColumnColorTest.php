<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PO-2026-00054: total 33.267,8744 (custo × qty, 4 casas) contra 33.267,8700
 * pagos (2 casas). Tela mostra os dois iguais; o badge ficava laranja.
 */
class PoPaidColumnColorTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->supplier = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);
    }

    /** Total 332678740 = 10 × 33.267874 (4 casas). */
    private function createPo(): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id, 'currency_code' => 'USD']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'description' => 'Bike',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_cost' => 33267874,
        ]);

        return $po->fresh();
    }

    private function pay(PurchaseOrder $po, int $amount): void
    {
        $scheduleItem = $po->paymentScheduleItems()->create([
            'label' => 'Stage',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => 'pending',
            'sort_order' => 1,
        ]);

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-09-01',
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $scheduleItem->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function colorFor(PurchaseOrder $po): string|array|null
    {
        $column = Livewire::test(ListPurchaseOrders::class)
            ->instance()
            ->getTable()
            ->getColumn('schedule_paid_total');

        $po = $po->fresh();
        $column->record($po);

        return $column->getColor($po->schedule_paid_total);
    }

    public function test_payment_rounded_to_cents_against_a_four_decimal_total_is_success(): void
    {
        $po = $this->createPo();
        $this->assertSame(332678740, $po->total);

        // Pago com 2 casas: 33.267,87.
        $this->pay($po, 332678700);

        $this->assertSame('success', $this->colorFor($po));
    }

    public function test_exact_payment_is_success(): void
    {
        $po = $this->createPo();
        $this->pay($po, 332678740);

        $this->assertSame('success', $this->colorFor($po));
    }

    public function test_gap_above_one_cent_is_warning(): void
    {
        $po = $this->createPo();
        $this->pay($po, 332678740 - 101);

        $this->assertSame('warning', $this->colorFor($po));
    }

    public function test_no_payment_is_gray(): void
    {
        $this->assertSame('gray', $this->colorFor($this->createPo()));
    }
}
