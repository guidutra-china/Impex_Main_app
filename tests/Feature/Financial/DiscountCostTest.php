<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscountCostTest extends TestCase
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

    public function test_discount_enum_case_is_complete(): void
    {
        $type = AdditionalCostType::DISCOUNT;

        $this->assertSame('discount', $type->value);
        $this->assertSame('Discount', $type->getEnglishLabel());
        $this->assertSame('warning', $type->getColor());
        $this->assertNotNull($type->getIcon());
        $this->assertSame('Desconto', __('enums.additional_cost_type.discount', [], 'pt_BR'));
        $this->assertSame('Discount', __('enums.additional_cost_type.discount', [], 'en'));
    }

    /** @param array<string, mixed> $overrides */
    private function makeDiscount(array $overrides = []): AdditionalCost
    {
        return $this->pi->additionalCosts()->create(array_merge([
            'cost_type' => AdditionalCostType::DISCOUNT->value,
            'description' => '5% goodwill discount',
            'amount' => 2_000_000, // digitado positivo: USD 200.00
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ], $overrides));
    }

    public function test_discount_amounts_are_normalized_negative_on_save(): void
    {
        $cost = $this->makeDiscount()->refresh();

        $this->assertSame(-2_000_000, $cost->amount);
        $this->assertSame(-2_000_000, $cost->amount_in_document_currency);

        // Re-save não dupla-nega.
        $cost->update(['description' => 'edited']);
        $this->assertSame(-2_000_000, $cost->refresh()->amount);
        $this->assertSame(-2_000_000, $cost->refresh()->amount_in_document_currency);
    }

    public function test_non_discount_amounts_are_untouched(): void
    {
        $cost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo',
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $this->assertSame(2_000_000, $cost->refresh()->amount);
    }

    public function test_discount_reduces_pi_grand_total(): void
    {
        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000, // 10 × 100.00 = 1000.00
        ]);
        $this->makeDiscount(); // −200.00

        $pi = $this->pi->fresh(['items', 'additionalCosts']);
        $this->assertSame(10_000_000, $pi->subtotal);
        $this->assertSame(8_000_000, $pi->grand_total);
    }

    private function syncCosts(): void
    {
        $action = new GeneratePaymentScheduleAction;
        $method = new \ReflectionMethod($action, 'syncAdditionalCosts');
        $method->invoke($action, $this->pi);
    }

    private function creditPsiFor(AdditionalCost $cost): ?PaymentScheduleItem
    {
        // Perna principal do crédito (sem tag de lado) — a perna do fornecedor
        // ([supplier-payable]) tem helper próprio, supplierLegPsiFor().
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->where('is_credit', true)
            ->withoutSideTags()
            ->first();
    }

    public function test_client_discount_creates_positive_credit_psi_on_pi(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();

        $psi = $this->creditPsiFor($cost);
        $this->assertNotNull($psi);
        $this->assertSame(2_000_000, $psi->amount, 'PSI sempre positivo (abs).');
        $this->assertTrue($psi->is_credit);
        $this->assertSame(ProformaInvoice::class, $psi->payable_type);
        $this->assertSame($this->pi->id, $psi->payable_id);
        $this->assertStringStartsWith('Discount:', $psi->label);
    }

    public function test_supplier_discount_credit_anchors_on_po_with_pi_fallback(): void
    {
        $cost = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);

        // Sem PO: fallback na PI.
        $this->syncCosts();
        $this->assertSame(ProformaInvoice::class, $this->creditPsiFor($cost)->payable_type);

        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);

        $this->syncCosts();
        $psi = $this->creditPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $psi->payable_type);
        $this->assertSame($po->id, $psi->payable_id);
        $this->assertSame(2_000_000, $psi->amount);
    }

    public function test_legacy_supplier_credit_cost_keeps_positive_psi(): void
    {
        // Regressão da uniformização abs(): custo supplier-billable legado (positivo).
        $cost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::TESTING->value,
            'description' => 'Quality deduction',
            'amount' => 1_500_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 1_500_000,
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $this->syncCosts();

        $psi = $this->creditPsiFor($cost);
        $this->assertSame(1_500_000, $psi->amount);
        $this->assertStringStartsWith('Credit:', $psi->label);
    }

    /** Consome o crédito aplicando-o contra uma parcela regular da PI, com pagamento aprovado. */
    private function consumeCredit(PaymentScheduleItem $creditPsi, int $amount): void
    {
        $target = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => '100% — Invoice',
            'percentage' => 100,
            'amount' => 10_000_000,
            'currency_code' => 'USD',
            'status' => \App\Domain\Financial\Enums\PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 99,
        ]);

        $payment = \App\Domain\Financial\Models\Payment::create([
            'direction' => \App\Domain\Financial\Enums\PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => 10_000_000 - $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-10',
            'status' => \App\Domain\Financial\Enums\PaymentStatus::PENDING_APPROVAL,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'allocated_amount' => 10_000_000 - $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 10_000_000 - $amount,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'credit_schedule_item_id' => $creditPsi->id,
            'allocated_amount' => 0,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);

        app(\App\Domain\Financial\Actions\ApprovePaymentAction::class)->approve($payment);
    }

    public function test_consumed_credit_stays_anchored_when_po_appears_later(): void
    {
        $cost = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);
        $this->syncCosts();
        $this->consumeCredit($this->creditPsiFor($cost), 2_000_000);

        PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        $this->syncCosts();

        $psi = $this->creditPsiFor($cost);
        $this->assertSame(ProformaInvoice::class, $psi->payable_type, 'Crédito consumido não muda de documento.');
    }

    public function test_consuming_discount_credit_marks_cost_paid_and_unblocks_finalization(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();
        $creditPsi = $this->creditPsiFor($cost);

        // Antes do consumo: custo PENDING bloqueia finalização.
        $blockers = implode(' | ', $this->pi->fresh()->getFinalizationBlockers());
        $this->assertStringContainsString('cost', strtolower($blockers));

        $this->consumeCredit($creditPsi, 2_000_000);

        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
        $this->assertSame(AdditionalCostStatus::PAID, $cost->refresh()->status);
    }

    public function test_partial_consumption_keeps_credit_pending(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();
        $creditPsi = $this->creditPsiFor($cost);

        $this->consumeCredit($creditPsi, 500_000); // 50.00 de 200.00

        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->refresh()->status);
    }

    public function test_waive_releases_finalization_blocker(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();

        app(\App\Domain\Financial\Actions\WaiveAdditionalCostAction::class)->execute($cost, null);

        $cost->refresh();
        $this->assertSame(AdditionalCostStatus::WAIVED, $cost->status);
        $blockers = implode(' | ', $this->pi->fresh()->getFinalizationBlockers());
        $this->assertStringNotContainsString('additional cost', strtolower($blockers));
    }

    public function test_switching_type_with_allocated_credit_is_blocked(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();
        $this->consumeCredit($this->creditPsiFor($cost), 2_000_000);

        $this->expectException(ValidationException::class);
        \App\Filament\RelationManagers\AdditionalCostsRelationManager::assertCostTypeSwitchable($cost->refresh(), AdditionalCostType::OTHER->value);
    }

    public function test_company_billable_discount_is_rejected_by_the_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeDiscount(['billable_to' => BillableTo::COMPANY->value]);
    }

    public function test_pi_pdf_shows_negative_discount_line_and_reduced_grand_total(): void
    {
        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000,
        ]);
        $this->makeDiscount();

        $data = (new \App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate($this->pi->fresh(['items', 'additionalCosts'])))->getData();

        $discountLine = collect($data['service_fees'])->first(fn ($fee) => $fee['raw_amount'] < 0);
        $this->assertNotNull($discountLine, 'Linha de desconto negativa deve aparecer nos service fees.');
        $this->assertSame(-2_000_000, $discountLine['raw_amount']);
        // totals são strings formatadas (formatMoney, Money::SCALE=10000):
        // subtotal 10_000_000 minor = 1,000.00; grand_total 8_000_000 minor = 800.00.
        $this->assertStringContainsString('800.00', $data['totals']['grand_total']);
        $this->assertStringContainsString('1,000.00', $data['totals']['subtotal']);
    }

    public function test_payment_form_lists_discount_credit_in_the_right_direction(): void
    {
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);

        $clientDiscount = $this->makeDiscount();
        $supplierDiscount = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);
        $this->syncCosts();

        $helper = new class
        {
            use \App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
        };

        $inbound = $helper::getCompanyCreditItems($this->client->id, 'inbound')->pluck('id');
        $this->assertTrue($inbound->contains($this->creditPsiFor($clientDiscount)->id));
        $this->assertFalse($inbound->contains($this->creditPsiFor($supplierDiscount)->id));

        $outbound = $helper::getCompanyCreditItems($this->supplier->id, 'outbound')->pluck('id');
        $this->assertTrue($outbound->contains($this->creditPsiFor($supplierDiscount)->id));
        $this->assertFalse($outbound->contains($this->creditPsiFor($clientDiscount)->id));
    }

    public function test_client_dn_generation_skips_discounts_and_supplier_cn_uses_abs(): void
    {
        $clientDiscount = $this->makeDiscount();
        $supplierDiscount = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);

        $dn = app(\App\Domain\Financial\Actions\GenerateDebitNoteFromCostsAction::class)
            ->execute($this->client, 'USD', $this->pi->fresh());
        $this->assertTrue(
            $dn === null || ! $dn->lineItems()->where('additional_cost_id', $clientDiscount->id)->exists(),
            'DN de cliente não pode faturar desconto.'
        );

        $cn = app(\App\Domain\Financial\Actions\GenerateCreditNoteFromCostsAction::class)
            ->execute($this->supplier, 'USD');
        $this->assertNotNull($cn);
        $line = $cn->lineItems()->where('additional_cost_id', $supplierDiscount->id)->first();
        $this->assertNotNull($line);
        $this->assertSame(2_000_000, $line->amount, 'Linha da CN sempre positiva (abs).');
    }

    /**
     * ViewDebitNote::autoPopulateAction (and ViewCreditNote's equivalent)
     * dedupe onto these now-public action methods instead of duplicating
     * the cost-selection query. Proving the seam here covers the page path
     * too, since the page calls exactly these methods with a
     * company-only scope (no PI/shipment), matching what these tests set up.
     */
    public function test_debit_note_page_seam_excludes_discount_via_shared_action_method(): void
    {
        $clientDiscount = $this->makeDiscount();
        $otherCost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::TESTING->value,
            'description' => 'Lab test fee',
            'amount' => 500_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 500_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $action = app(\App\Domain\Financial\Actions\GenerateDebitNoteFromCostsAction::class);
        // Page-shaped scope: client company, no PI/shipment narrowing.
        $costs = $action->getUnbilledCosts($this->client, null, null);

        $costIds = $costs->pluck('id');
        $this->assertFalse($costIds->contains($clientDiscount->id), 'DN page seam não pode incluir desconto.');
        $this->assertTrue($costIds->contains($otherCost->id));
    }

    public function test_credit_note_page_seam_includes_discount_with_positive_line_via_shared_action_method(): void
    {
        $supplierDiscount = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);

        $action = app(\App\Domain\Financial\Actions\GenerateCreditNoteFromCostsAction::class);
        // Page-shaped scope: supplier company, no PO/shipment narrowing.
        $costs = $action->getUnbilledCosts($this->supplier, null, null);

        $this->assertTrue($costs->pluck('id')->contains($supplierDiscount->id), 'CN page seam deve incluir desconto de fornecedor.');

        $cn = \App\Domain\Financial\Models\CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => \App\Domain\Financial\Enums\PartyType::SUPPLIER,
            'currency_code' => 'USD',
            'status' => \App\Domain\Financial\Enums\CreditNoteStatus::DRAFT,
        ]);
        $action->createLineItem($cn, $costs->firstWhere('id', $supplierDiscount->id));

        $line = $cn->lineItems()->where('additional_cost_id', $supplierDiscount->id)->first();
        $this->assertNotNull($line);
        $this->assertSame(2_000_000, $line->amount, 'Linha da CN via seam da página sempre positiva (abs).');
    }

    // --- Perna do fornecedor no desconto (espelho do supplier payable) ---

    /** @param array<string, mixed> $overrides */
    private function makeDiscountWithSupplierLeg(array $overrides = []): AdditionalCost
    {
        return $this->makeDiscount(array_merge([
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 1_500_000, // USD 150.00 de desconto no custo
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 1_500_000,
        ], $overrides));
    }

    private function supplierLegPsiFor(AdditionalCost $cost): ?PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->first();
    }

    public function test_discount_supplier_leg_is_a_credit_anchored_on_supplier_po(): void
    {
        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        $cost = $this->makeDiscountWithSupplierLeg();

        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $this->syncCosts();

        $leg = $this->supplierLegPsiFor($cost);
        $this->assertNotNull($leg);
        $this->assertTrue($leg->is_credit, 'Perna de desconto do fornecedor é CRÉDITO, não pagável.');
        $this->assertSame(1_500_000, $leg->amount);
        $this->assertSame(PurchaseOrder::class, $leg->payable_type);
        $this->assertSame($po->id, $leg->payable_id);
        $this->assertStringStartsWith('Discount:', $leg->label);

        // Perna do cliente intocada: crédito próprio, na PI, sem tag.
        $clientLeg = $this->creditPsiFor($cost);
        $this->assertNotSame($leg->id, $clientLeg->id);
        $this->assertSame(ProformaInvoice::class, $clientLeg->payable_type);
        $this->assertSame(2_000_000, $clientLeg->amount);
    }

    public function test_discount_supplier_leg_reanchors_to_po_created_later(): void
    {
        $cost = $this->makeDiscountWithSupplierLeg();
        $action = app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class);

        $action->execute($cost, $this->pi);
        $this->assertSame(ProformaInvoice::class, $this->supplierLegPsiFor($cost)->payable_type);

        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        $action->execute($cost->refresh(), $this->pi);

        $leg = $this->supplierLegPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $leg->payable_type);
        $this->assertSame($po->id, $leg->payable_id);
    }

    public function test_discount_legs_split_by_direction_in_credit_forms(): void
    {
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);

        // Sem PO: as DUAS pernas ancoram na PI — só a tag separa as direções.
        $cost = $this->makeDiscountWithSupplierLeg();
        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $this->syncCosts();

        $clientLeg = $this->creditPsiFor($cost);
        $supplierLeg = $this->supplierLegPsiFor($cost);

        $helper = new class
        {
            use \App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
        };

        $inbound = $helper::getCompanyCreditItems($this->client->id, 'inbound')->pluck('id');
        $this->assertTrue($inbound->contains($clientLeg->id), 'Perna do cliente no INBOUND.');
        $this->assertFalse($inbound->contains($supplierLeg->id), 'Perna do fornecedor NÃO vaza p/ INBOUND.');

        $outbound = $helper::getCompanyCreditItems($this->supplier->id, 'outbound')->pluck('id');
        $this->assertTrue($outbound->contains($supplierLeg->id), 'Perna do fornecedor no OUTBOUND.');
        $this->assertFalse($outbound->contains($clientLeg->id), 'Perna do cliente NÃO vaza p/ OUTBOUND.');
    }

    public function test_consuming_supplier_leg_sets_supplier_payable_status_only(): void
    {
        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        $cost = $this->makeDiscountWithSupplierLeg();
        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);
        $leg = $this->supplierLegPsiFor($cost);

        // Consumo OUTBOUND: aplica o crédito contra uma parcela da PO.
        $target = PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => '100% — PO',
            'percentage' => 100,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 99,
        ]);

        $payment = \App\Domain\Financial\Models\Payment::create([
            'direction' => \App\Domain\Financial\Enums\PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 3_500_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-10',
            'status' => \App\Domain\Financial\Enums\PaymentStatus::PENDING_APPROVAL,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'allocated_amount' => 3_500_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 3_500_000,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'credit_schedule_item_id' => $leg->id,
            'allocated_amount' => 0,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 1_500_000,
        ]);

        app(\App\Domain\Financial\Actions\ApprovePaymentAction::class)->approve($payment);

        $cost->refresh();
        $this->assertSame(PaymentScheduleStatus::PAID, $leg->refresh()->status);
        $this->assertSame(AdditionalCostStatus::PAID, $cost->supplier_payable_status);
        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->status, 'Perna do cliente segue aberta.');
    }

    /** Consome a perna do fornecedor contra uma parcela da PO (pagamento aprovado). */
    private function consumeSupplierLeg(AdditionalCost $cost, PurchaseOrder $po, int $amount): PaymentScheduleItem
    {
        $leg = $this->supplierLegPsiFor($cost);

        $target = PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => '100% — PO',
            'percentage' => 100,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 98,
        ]);

        $payment = \App\Domain\Financial\Models\Payment::create([
            'direction' => \App\Domain\Financial\Enums\PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 5_000_000 - $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-10',
            'status' => \App\Domain\Financial\Enums\PaymentStatus::PENDING_APPROVAL,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'allocated_amount' => 5_000_000 - $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 5_000_000 - $amount,
        ]);

        \App\Domain\Financial\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $target->id,
            'credit_schedule_item_id' => $leg->id,
            'allocated_amount' => 0,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);

        app(\App\Domain\Financial\Actions\ApprovePaymentAction::class)->approve($payment);

        return $leg->refresh();
    }

    public function test_consumed_supplier_leg_survives_waive_and_regeneration(): void
    {
        // Sem PO: perna fica na PI — é aqui que o cleanup de órfãos do
        // regenerate da PI poderia apagá-la após o waive.
        $cost = $this->makeDiscountWithSupplierLeg();
        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        // Consome ANTES de re-sync: a perna segue ancorada na PI (pinada).
        $leg = $this->consumeSupplierLeg($cost, $po, 1_500_000);
        $this->assertSame(ProformaInvoice::class, $leg->payable_type);

        app(\App\Domain\Financial\Actions\WaiveAdditionalCostAction::class)->execute($cost, null);
        $this->syncCosts(); // cleanup de órfãos roda aqui (custo waived sai dos ativos)

        $this->assertNotNull(
            PaymentScheduleItem::find($leg->id),
            'Perna consumida não pode ser apagada pelo cleanup de órfãos — o histórico vive em creditAllocations.'
        );
    }

    public function test_has_settlement_history_covers_credit_applications(): void
    {
        $cost = $this->makeDiscountWithSupplierLeg();
        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        $this->assertFalse($cost->hasSettlementHistory(), 'Sem consumo: sem histórico, delete permitido.');

        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
        $this->consumeSupplierLeg($cost, $po, 1_500_000);

        $this->assertTrue($cost->refresh()->hasSettlementHistory(), 'Crédito consumido conta como histórico (creditAllocations).');
    }

    public function test_waive_covers_discount_supplier_leg(): void
    {
        $cost = $this->makeDiscountWithSupplierLeg();
        app(\App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction::class)->execute($cost, $this->pi);

        app(\App\Domain\Financial\Actions\WaiveAdditionalCostAction::class)->execute($cost, null);

        $cost->refresh();
        $this->assertSame(AdditionalCostStatus::WAIVED, $cost->supplier_payable_status);
        $this->assertSame(PaymentScheduleStatus::WAIVED, $this->supplierLegPsiFor($cost)->status);
    }
}
