<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\PaymentScheduleRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makePi(): ProformaInvoice
    {
        $company = Company::factory()->create();

        return ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'currency_code' => 'USD',
        ]);
    }

    private function makeScheduleItem(ProformaInvoice $pi, array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Deposit 30%',
            'percentage' => 30,
            'amount' => 30000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(10),
        ], $overrides));
    }

    private function makeApprovedPayment(Company $company, int $amount): Payment
    {
        return Payment::create([
            'direction' => PaymentDirection::INBOUND->value,
            'company_id' => $company->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->subDays(3),
            'reference' => 'WIRE-001',
            'status' => PaymentStatus::APPROVED->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ProformaInvoice $pi): array
    {
        $template = new PaymentStatementPdfTemplate($pi->fresh());

        $method = new \ReflectionMethod($template, 'getDocumentData');

        return $method->invoke($template);
    }

    public function test_pi_items_listed_with_qty_unit_price_and_total(): void
    {
        $pi = $this->makePi();

        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => \App\Domain\Catalog\Models\Product::factory(),
            'quantity' => 100,
            'unit_price' => 2500, // USD 0.25 (minor units)
            'sort_order' => 1,
        ]);
        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => \App\Domain\Catalog\Models\Product::factory(),
            'quantity' => 10,
            'unit_price' => 100000, // USD 10.00
            'sort_order' => 2,
        ]);

        $data = $this->data($pi);

        $this->assertCount(2, $data['pi_items']);
        $this->assertSame(100, $data['pi_items'][0]['quantity']);
        $this->assertSame(1250000, $data['raw_pi_items_total']);
    }

    public function test_pi_item_code_prefers_client_code_then_model_number_over_sku(): void
    {
        $pi = $this->makePi();

        $withClientCode = \App\Domain\Catalog\Models\Product::factory()->create([
            'model_number' => 'MOD-A',
            'sku' => 'SKU-A',
        ]);
        $withClientCode->companies()->attach($pi->company_id, [
            'role' => 'client',
            'external_code' => 'CLIENT-55',
        ]);

        $plain = \App\Domain\Catalog\Models\Product::factory()->create([
            'model_number' => 'MOD-B',
            'sku' => 'SKU-B',
        ]);

        foreach ([$withClientCode, $plain] as $i => $product) {
            \Database\Factories\ProformaInvoiceItemFactory::new()->create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'sort_order' => $i + 1,
            ]);
        }

        $items = $this->data($pi)['pi_items'];

        $this->assertSame('CLIENT-55', $items[0]['product_code']);
        $this->assertSame('MOD-B', $items[1]['product_code']);
    }

    public function test_pi_item_description_is_breakable_and_limited(): void
    {
        $pi = $this->makePi();

        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => \App\Domain\Catalog\Models\Product::factory(),
            // Lista longa sem espaço após vírgulas — estourava a largura da
            // tabela no DomPDF (token inquebrável).
            'description' => 'Fits: '.str_repeat('9560STS,9570STS,9650CTS,', 15),
            'quantity' => 1,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);

        $description = $this->data($pi)['pi_items'][0]['description'];

        $this->assertLessThanOrEqual(153, mb_strlen($description));
        $this->assertStringEndsWith('...', $description);
        $this->assertStringContainsString('9560STS, 9570STS', $description);
        $this->assertStringNotContainsString('9560STS,9570STS', $description);
    }

    public function test_schedule_payments_and_summary(): void
    {
        $pi = $this->makePi();

        $deposit = $this->makeScheduleItem($pi);
        $this->makeScheduleItem($pi, [
            'label' => 'Balance 70%',
            'percentage' => 70,
            'amount' => 70000,
            'sort_order' => 2,
        ]);

        $payment = $this->makeApprovedPayment($pi->company, 30000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $deposit->id,
            'allocated_amount' => 30000,
            'allocated_amount_in_document_currency' => 30000,
        ]);

        $data = $this->data($pi);

        $this->assertCount(2, $data['schedule']);
        $this->assertSame('Deposit 30%', $data['schedule'][0]['label']);

        $this->assertCount(1, $data['payments']);
        // Número do pagamento primeiro, referência SWIFT (livre) em seguida.
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6} · WIRE-001$/', $data['payments'][0]['reference']);

        // 30.000 pagos de 100.000 -> outstanding 70.000 (minor units)
        $this->assertSame(70000, $data['totals']['raw_outstanding']);
        $this->assertSame(30000, $data['totals']['raw_payments_received']);
    }

    public function test_supplier_payable_tagged_items_are_excluded(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi);
        $this->makeScheduleItem($pi, [
            'label' => 'Supplier side',
            'sort_order' => 2,
            'notes' => 'auto '.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG,
        ]);

        $data = $this->data($pi);

        $this->assertCount(1, $data['schedule']);
        $this->assertSame('Deposit 30%', $data['schedule'][0]['label']);
    }

    public function test_credit_application_is_listed_and_reduces_balance(): void
    {
        $pi = $this->makePi();
        $item = $this->makeScheduleItem($pi, ['amount' => 50000]);

        $creditItem = $this->makeScheduleItem($pi, [
            'label' => 'Credit CN-2026-001',
            'amount' => 10000,
            'is_credit' => true,
            'sort_order' => 3,
        ]);

        $payment = $this->makeApprovedPayment($pi->company, 0);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $item->id,
            'credit_schedule_item_id' => $creditItem->id,
            'allocated_amount' => 10000,
            'allocated_amount_in_document_currency' => 10000,
        ]);

        $data = $this->data($pi);

        // PSI de credito nao entra no cronograma
        $this->assertCount(1, $data['schedule']);
        // aplicacao de credito listada
        $this->assertCount(1, $data['credits']);
        $this->assertSame(10000, $data['totals']['raw_credits_applied']);
        // credito nao e "payment received"
        $this->assertCount(0, $data['payments']);
        $this->assertSame(40000, $data['totals']['raw_outstanding']);
    }

    public function test_debit_notes_included_cancelled_and_foreign_currency_handled(): void
    {
        $pi = $this->makePi();
        $paidItem = $this->makeScheduleItem($pi, [
            'amount' => 10000,
            'status' => PaymentScheduleStatus::PAID->value,
        ]);
        $payment = $this->makeApprovedPayment($pi->company, 10000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $paidItem->id,
            'allocated_amount' => 10000,
            'allocated_amount_in_document_currency' => 10000,
        ]);

        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 5000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::ISSUED->value,
            'issued_at' => now()->subDay(),
        ]);
        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 9999,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::CANCELLED->value,
            'issued_at' => now()->subDay(),
        ]);
        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 8888,
            'currency_code' => 'BRL',
            'status' => DebitNoteStatus::ISSUED->value,
            'issued_at' => now()->subDay(),
        ]);

        $data = $this->data($pi);

        // cancelada fora; USD + BRL listadas
        $this->assertCount(2, $data['debit_notes']);

        $inTotals = collect($data['debit_notes'])->where('in_totals', true);
        $this->assertCount(1, $inTotals);

        // so a DN USD entra no outstanding (schedule 100% pago)
        $this->assertSame(5000, $data['totals']['raw_outstanding']);
    }

    public function test_pdf_renders_via_generator_service(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi);

        $template = new PaymentStatementPdfTemplate($pi->fresh());

        $service = new \App\Domain\Infrastructure\Pdf\PdfGeneratorService(
            new \App\Domain\Infrastructure\Pdf\PdfRenderer,
            new \App\Domain\Infrastructure\Services\DocumentService,
        );

        $content = $service->preview($template);

        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame("PS-{$pi->reference}.pdf", $template->getFilename());
    }

    public function test_payment_statement_action_visible_on_pi_schedule(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $pi = $this->makePi();
        $this->makeScheduleItem($pi);

        Livewire::test(PaymentScheduleRelationManager::class, [
            'ownerRecord' => $pi->fresh(),
            'pageClass' => EditProformaInvoice::class,
        ])
            ->assertSuccessful()
            ->assertActionVisible('paymentStatement');
    }

    public function test_waived_item_stays_out_of_outstanding(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi, [
            'amount' => 20000,
            'status' => PaymentScheduleStatus::WAIVED->value,
        ]);

        $data = $this->data($pi);

        $this->assertSame(0, $data['totals']['raw_outstanding']);
        $this->assertSame('Waived', $data['schedule'][0]['status']);
    }

    private function makeClientCost(ProformaInvoice $pi, int $amount, array $overrides = []): AdditionalCost
    {
        return AdditionalCost::create(array_merge([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $pi->id,
            'cost_type' => AdditionalCostType::COMMISSION,
            'description' => '5% comission',
            'amount' => $amount,
            'currency_code' => 'USD',
            'amount_in_document_currency' => $amount,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => now()->subDays(5),
            'status' => AdditionalCostStatus::PENDING,
        ], $overrides));
    }

    /**
     * O commission_mode não é consultado pelo GeneratePaymentScheduleAction: ele
     * gera parcela para todo custo billable_to=client. Esconder a comissão
     * EMBEDDED da lista de custos fazia o "Proforma Invoice Total" impresso
     * ficar menor do que o cronograma do próprio documento cobra
     * (prod: PI-2026-00078, total 4.325,06 com uma parcela de 219,14 em aberto).
     */
    public function test_embedded_commission_is_listed_and_counted_in_the_grand_total(): void
    {
        $pi = $this->makePi();
        $item = $pi->items()->create([
            'description' => 'Laser welding machine',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'unit' => 'pcs',
        ]);

        $cost = $this->makeClientCost($pi, 50_000, [
            'commission_mode' => CommissionType::EMBEDDED,
            'commission_rate' => 5,
        ]);

        // A parcela que o sistema realmente cobra do cliente por esse custo.
        $this->makeScheduleItem($pi, [
            'label' => 'Commission: 5% comission',
            'percentage' => 0,
            'amount' => 50_000,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => 2,
        ]);

        $data = $this->data($pi);

        $this->assertCount(1, $data['additional_costs']);
        $this->assertSame('5% comission', $data['additional_costs'][0]['description']);
        $this->assertSame(50_000, $data['additional_costs'][0]['raw_amount']);

        $itemsTotal = $item->quantity * $item->unit_price;
        $this->assertSame(
            $this->formatMoneyLike($itemsTotal + 50_000),
            $data['totals']['pi_grand_total'],
            'O total impresso tem de incluir o custo que o cronograma cobra.',
        );
    }

    public function test_waived_and_non_client_costs_stay_out_of_the_grand_total(): void
    {
        $pi = $this->makePi();
        $pi->items()->create([
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'unit' => 'pcs',
        ]);

        $this->makeClientCost($pi, 70_000, [
            'description' => 'Waived charge',
            'cost_type' => AdditionalCostType::OTHER,
            'status' => AdditionalCostStatus::WAIVED,
        ]);
        $this->makeClientCost($pi, 90_000, [
            'description' => 'Company absorbs',
            'cost_type' => AdditionalCostType::OTHER,
            'billable_to' => BillableTo::COMPANY,
        ]);

        $data = $this->data($pi);

        $this->assertSame([], $data['additional_costs']);
        $this->assertSame($this->formatMoneyLike(1_000_000), $data['totals']['pi_grand_total']);
    }

    private function formatMoneyLike(int $minorUnits): string
    {
        return number_format($minorUnits / \App\Domain\Infrastructure\Support\Money::SCALE, 2, '.', ',');
    }
}
