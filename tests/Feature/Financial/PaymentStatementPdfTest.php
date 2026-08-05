<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
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
        $this->assertSame('WIRE-001', $data['payments'][0]['reference']);

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
}
