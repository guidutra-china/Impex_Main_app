<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Actions\WaiveAdditionalCostAction;
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
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
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

    public function test_assert_side_removable_blocks_currency_switch_when_allocated(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $this->payScheduleItem($this->supplierPsiFor($cost), 3_500_000);

        $this->expectException(ValidationException::class);
        SyncSupplierPayableScheduleItemAction::assertSideRemovable($cost->refresh(), true, $this->supplier->id, 'CNY');
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
        $cost->refresh();
        $this->assertSame(AdditionalCostStatus::INVOICED, $cost->supplier_payable_status, 'Cancel derruba o PSI p/ DUE → INVOICED.');
        $this->assertSame(AdditionalCostStatus::PENDING, $cost->status, 'Lado cliente segue intocado após cancel.');
    }

    public function test_schedule_regeneration_upserts_supplier_psi_without_duplicating(): void
    {
        $cost = $this->makePayableCost();
        $sync = new GeneratePaymentScheduleAction;

        // Duas passadas do sync de custos = 1 linha só.
        $syncMethod = new \ReflectionMethod($sync, 'syncAdditionalCosts');
        $syncMethod->invoke($sync, $this->pi);
        $syncMethod->invoke($sync, $this->pi);

        $rows = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3_500_000, $rows->first()->amount);
    }

    public function test_company_absorbed_cost_keeps_supplier_psi_but_no_client_psi(): void
    {
        $cost = $this->makePayableCost(['billable_to' => BillableTo::COMPANY->value]);
        $sync = new GeneratePaymentScheduleAction;
        $syncMethod = new \ReflectionMethod($sync, 'syncAdditionalCosts');
        $syncMethod->invoke($sync, $this->pi);

        $this->assertNotNull($this->supplierPsiFor($cost));

        $clientRow = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withoutSideTags()
            ->first();
        $this->assertNull($clientRow, 'COMPANY absorve: sem linha de cliente.');
    }

    public function test_supplier_psi_appears_in_payables_and_not_in_receivables(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $psi = $this->supplierPsiFor($cost);

        $this->assertTrue(OpenScheduleItemsQuery::payables()->pluck('id')->contains($psi->id));
        $this->assertFalse(OpenScheduleItemsQuery::receivables()->pluck('id')->contains($psi->id));
    }

    public function test_leak_regression_client_cost_with_supplier_filled_stays_out_of_payables(): void
    {
        // Custo CLIENT com "Supplier (if applicable)" preenchido mas SEM lado pagável:
        // a linha recebível não pode vazar para o AP (bug pré-existente).
        $cost = $this->makeCost(['supplier_company_id' => $this->supplier->id]);
        $receivable = $this->makeScheduleItem([
            'label' => 'Other: Logo development',
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
        ]);

        $this->assertFalse(OpenScheduleItemsQuery::payables()->pluck('id')->contains($receivable->id));
        $this->assertTrue(OpenScheduleItemsQuery::receivables()->pluck('id')->contains($receivable->id));
    }

    public function test_payment_form_lists_supplier_psi_outbound_only(): void
    {
        $this->supplier->companyRoles()->create(['role' => 'supplier']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $psi = $this->supplierPsiFor($cost);

        $helper = new class
        {
            use HasPaymentFormSections;
        };

        $outbound = $helper::getCompanyScheduleItems($this->supplier->id, 'outbound')->pluck('id');
        $this->assertTrue($outbound->contains($psi->id));

        $inbound = $helper::getCompanyScheduleItems($this->client->id, 'inbound')->pluck('id');
        $this->assertFalse($inbound->contains($psi->id));
    }

    public function test_waiving_cost_waives_supplier_side_too(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        app(WaiveAdditionalCostAction::class)->execute($cost, null);

        $cost->refresh();
        $this->assertSame(AdditionalCostStatus::WAIVED, $cost->status);
        $this->assertSame(AdditionalCostStatus::WAIVED, $cost->supplier_payable_status);
        $this->assertSame(PaymentScheduleStatus::WAIVED, $this->supplierPsiFor($cost)->status);
    }

    public function test_waiving_cost_does_not_clobber_paid_supplier_side(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $psi = $this->supplierPsiFor($cost);
        $this->payScheduleItem($psi, 3_500_000);

        $cost->refresh();
        app(WaiveAdditionalCostAction::class)->execute($cost, null);

        $cost->refresh();
        $this->assertSame(AdditionalCostStatus::WAIVED, $cost->status);
        $this->assertSame(AdditionalCostStatus::PAID, $cost->supplier_payable_status, 'Lado pago não pode ser sobrescrito pelo waive.');
        $this->assertSame(PaymentScheduleStatus::PAID, $this->supplierPsiFor($cost)->status, 'PSI liquidado não pode ser tocado pelo waive.');
    }

    public function test_audit_command_lists_untagged_cost_rows_that_left_ap(): void
    {
        $cost = $this->makeCost(['supplier_company_id' => $this->supplier->id]);
        $this->makeScheduleItem([
            'label' => 'Other: Logo development',
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
        ]);

        $this->artisan('financial:audit-supplier-cost-leak')
            ->expectsOutputToContain('Other: Logo development')
            ->assertSuccessful();
    }

    public function test_full_regenerate_preserves_supplier_psi_and_statuses(): void
    {
        $cost = $this->makePayableCost();
        $action = new GeneratePaymentScheduleAction;
        $syncMethod = new \ReflectionMethod($action, 'syncAdditionalCosts');
        $syncMethod->invoke($action, $this->pi);

        $action->regenerate($this->pi->refresh());

        $rows = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3_500_000, $rows->first()->amount);
        $this->assertSame(PaymentScheduleStatus::DUE, $rows->first()->status);
    }

    // --- PO anchoring (recebe pela PI, repassa pela PO) ---

    private function makeSupplierPo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
    }

    public function test_supplier_psi_anchors_on_supplier_po_when_it_exists(): void
    {
        $po = $this->makeSupplierPo();
        $cost = $this->makePayableCost();

        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $psi = $this->supplierPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $psi->payable_type);
        $this->assertSame($po->id, $psi->payable_id);
    }

    public function test_supplier_psi_reanchors_to_po_created_later(): void
    {
        $cost = $this->makePayableCost();
        $action = app(SyncSupplierPayableScheduleItemAction::class);

        $action->execute($cost, $this->pi);
        $original = $this->supplierPsiFor($cost);
        $this->assertSame(ProformaInvoice::class, $original->payable_type);

        $po = $this->makeSupplierPo();
        $action->execute($cost->refresh(), $this->pi);

        $rows = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($original->id, $rows->first()->id, 'Re-ancora a MESMA linha, não cria outra.');
        $this->assertSame(PurchaseOrder::class, $rows->first()->payable_type);
        $this->assertSame($po->id, $rows->first()->payable_id);
    }

    public function test_allocated_supplier_psi_stays_put_when_po_appears(): void
    {
        $cost = $this->makePayableCost();
        $action = app(SyncSupplierPayableScheduleItemAction::class);
        $action->execute($cost, $this->pi);
        $this->payScheduleItem($this->supplierPsiFor($cost), 3_500_000);

        $this->makeSupplierPo();
        $action->execute($cost->refresh(), $this->pi);

        $psi = $this->supplierPsiFor($cost);
        $this->assertSame(ProformaInvoice::class, $psi->payable_type, 'Linha alocada não muda de payable.');
    }

    public function test_cancelled_po_is_skipped_as_anchor(): void
    {
        $po = $this->makeSupplierPo();
        $po->update(['status' => 'cancelled']);

        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $this->assertSame(ProformaInvoice::class, $this->supplierPsiFor($cost)->payable_type);
    }

    public function test_generating_pos_reanchors_supplier_psi_automatically(): void
    {
        $cost = $this->makePayableCost();
        app(SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $this->assertSame(ProformaInvoice::class, $this->supplierPsiFor($cost)->payable_type);

        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'product_id' => \App\Domain\Catalog\Models\Product::factory(),
        ]);

        (new \App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction)->execute($this->pi->fresh('items'));

        $po = PurchaseOrder::where('proforma_invoice_id', $this->pi->id)
            ->where('supplier_company_id', $this->supplier->id)
            ->firstOrFail();

        $psi = $this->supplierPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $psi->payable_type);
        $this->assertSame($po->id, $psi->payable_id);
    }
}
