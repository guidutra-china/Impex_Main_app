<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPayableCostSideTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->supplier = Company::create(['name' => 'Shenzhen Maker', 'status' => 'active']);
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeCost(array $overrides = []): AdditionalCost
    {
        return $this->pi->additionalCosts()->create(array_merge([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo development',
            'amount' => 5_000_000, // USD 500.00
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 5_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ], $overrides));
    }

    public function test_supplier_payable_columns_round_trip_and_side_detection(): void
    {
        $cost = $this->makeCost([
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 3_500_000, // USD 350.00
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 3_500_000,
            'supplier_payable_due_date' => '2026-08-15',
        ]);

        $cost->refresh();

        $this->assertSame(3_500_000, $cost->supplier_payable_amount);
        $this->assertSame('2026-08-15', $cost->supplier_payable_due_date->format('Y-m-d'));
        $this->assertNull($cost->supplier_payable_status);
        $this->assertTrue($cost->hasSupplierPayableSide());
    }

    public function test_side_is_inactive_without_amount_or_supplier_or_when_supplier_billable(): void
    {
        $noAmount = $this->makeCost(['supplier_company_id' => $this->supplier->id]);
        $this->assertFalse($noAmount->hasSupplierPayableSide());

        $noSupplier = $this->makeCost(['supplier_payable_amount' => 1_000_000]);
        $this->assertFalse($noSupplier->hasSupplierPayableSide());

        $supplierBillable = $this->makeCost([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 1_000_000,
        ]);
        $this->assertFalse($supplierBillable->hasSupplierPayableSide());
    }

    /** @param array<string, mixed> $overrides */
    private function makeScheduleItem(array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Test item',
            'percentage' => 0,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ], $overrides));
    }

    public function test_side_tag_scopes_filter_by_notes(): void
    {
        $plain = $this->makeScheduleItem(['label' => 'plain', 'notes' => null]);
        $forwarder = $this->makeScheduleItem([
            'label' => 'fwd',
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG.' freight',
        ]);
        $supplier = $this->makeScheduleItem([
            'label' => 'sup',
            'notes' => PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.' logo',
        ]);
        $untagged = $this->makeScheduleItem(['label' => 'untagged', 'notes' => 'balance note']);

        $without = PaymentScheduleItem::query()->withoutSideTags()->pluck('id');
        $this->assertTrue($without->contains($plain->id));
        $this->assertFalse($without->contains($forwarder->id));
        $this->assertFalse($without->contains($supplier->id));
        $this->assertTrue($without->contains($untagged->id));

        $tagged = PaymentScheduleItem::query()
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->pluck('id');
        $this->assertSame([$supplier->id], $tagged->all());

        $fwdTagged = PaymentScheduleItem::query()
            ->withSideTag(PaymentScheduleItem::FORWARDER_PAYABLE_TAG)
            ->pluck('id');
        $this->assertSame([$forwarder->id], $fwdTagged->all());
    }

    /** @param array<string, mixed> $overrides */
    private function makePayableCost(array $overrides = []): AdditionalCost
    {
        return $this->makeCost(array_merge([
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 3_500_000,
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 3_500_000,
            'supplier_payable_due_date' => '2026-08-15',
        ], $overrides));
    }

    private function supplierPsiFor(AdditionalCost $cost): ?PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->first();
    }

    public function test_sync_creates_tagged_psi_on_owner_document(): void
    {
        $cost = $this->makePayableCost();

        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $psi = $this->supplierPsiFor($cost);
        $this->assertNotNull($psi);
        $this->assertSame(ProformaInvoice::class, $psi->payable_type);
        $this->assertSame($this->pi->id, $psi->payable_id);
        $this->assertSame(3_500_000, $psi->amount);
        $this->assertSame('USD', $psi->currency_code);
        $this->assertSame('2026-08-15', $psi->due_date->format('Y-m-d'));
        $this->assertFalse($psi->is_credit);
        $this->assertStringContainsString('Shenzhen Maker', $psi->label);
    }

    public function test_sync_works_with_shipment_owner_too(): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHP-TEST-001',
            'company_id' => $this->client->id,
            'issue_date' => '2026-08-01',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);

        $cost = $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo development',
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 5_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 3_500_000,
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 3_500_000,
        ]);

        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $shipment);

        $psi = $this->supplierPsiFor($cost);
        $this->assertNotNull($psi);
        $this->assertSame(Shipment::class, $psi->payable_type);
        $this->assertSame($shipment->id, $psi->payable_id);
    }

    public function test_sync_is_idempotent_and_updates_amount(): void
    {
        $cost = $this->makePayableCost();
        $action = app(SyncSupplierPayableScheduleItemAction::class);

        $action->execute($cost, $this->pi);
        $cost->update(['supplier_payable_amount' => 4_000_000]);
        $action->execute($cost->refresh(), $this->pi);

        $rows = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(4_000_000, $rows->first()->amount);
    }

    public function test_sync_removes_unallocated_psi_when_side_deactivated(): void
    {
        $cost = $this->makePayableCost();
        $action = app(SyncSupplierPayableScheduleItemAction::class);
        $action->execute($cost, $this->pi);

        $cost->update(['supplier_payable_amount' => null]);
        $action->execute($cost->refresh(), $this->pi);

        $this->assertNull($this->supplierPsiFor($cost));
    }

    /** Cria um Payment OUTBOUND aprovado alocado ao PSI, disparando o reconcile via observer/approve. */
    private function payScheduleItem(PaymentScheduleItem $psi, int $amount): Payment
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-10',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);

        app(\App\Domain\Financial\Actions\ApprovePaymentAction::class)->approve($payment);

        return $payment;
    }

    public function test_sync_keeps_allocated_psi_when_side_deactivated(): void
    {
        $cost = $this->makePayableCost();
        $action = app(SyncSupplierPayableScheduleItemAction::class);
        $action->execute($cost, $this->pi);

        $psi = $this->supplierPsiFor($cost);
        $this->payScheduleItem($psi, 3_500_000);

        $cost->update(['supplier_payable_amount' => null]);
        $action->execute($cost->refresh(), $this->pi);

        $this->assertNotNull($this->supplierPsiFor($cost), 'PSI alocado não pode ser deletado pelo sync.');
    }

    public function test_sync_never_creates_psi_for_supplier_billable_cost(): void
    {
        $cost = $this->makeCost([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 3_500_000,
        ]);

        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $this->assertNull($this->supplierPsiFor($cost));
    }

    public function test_assert_side_removable_throws_when_allocated(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $this->payScheduleItem($this->supplierPsiFor($cost), 3_500_000);

        $this->expectException(ValidationException::class);
        SyncSupplierPayableScheduleItemAction::assertSideRemovable($cost->refresh(), false, $this->supplier->id);
    }

    public function test_assert_side_removable_passes_when_unallocated_or_unchanged(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        // Sem alocação: pode desativar.
        SyncSupplierPayableScheduleItemAction::assertSideRemovable($cost, false, null);

        // Com alocação mas mantendo lado e fornecedor: pode.
        $this->payScheduleItem($this->supplierPsiFor($cost), 3_500_000);
        SyncSupplierPayableScheduleItemAction::assertSideRemovable($cost->refresh(), true, $this->supplier->id);

        $this->assertTrue(true); // nenhuma exceção lançada
    }

    public function test_paying_supplier_psi_sets_supplier_payable_status_without_touching_client_status(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $psi = $this->supplierPsiFor($cost);

        $payment = $this->payScheduleItem($psi, 3_500_000);

        $cost->refresh();
        $this->assertSame(PaymentScheduleStatus::PAID, $psi->refresh()->status);
        $this->assertSame(AdditionalCostStatus::PAID, $cost->supplier_payable_status);
        $this->assertSame(AdditionalCostStatus::PENDING, $cost->status, 'Lado cliente não pode ser tocado.');

        // Cancelar o pagamento reverte o lado fornecedor.
        app(\App\Domain\Financial\Actions\ApprovePaymentAction::class)->cancel($payment);
        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->refresh()->supplier_payable_status);
    }
}
