<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;
use Database\Factories\ProformaInvoiceItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Payment Statement da PI deve mostrar os custos adicionais como linhas
 * visíveis — inclusive descontos (negativos) — e o grand total deve somar
 * exatamente as linhas exibidas (sem WAIVED, sem comissão embedded).
 */
class PaymentStatementDiscountTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000, // 10 × 100.00 = 1,000.00
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeCost(array $overrides = []): void
    {
        $this->pi->additionalCosts()->create(array_merge([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo development',
            'amount' => 4_000_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 4_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ], $overrides));
    }

    private function statementData(): array
    {
        return (new PaymentStatementPdfTemplate($this->pi->fresh(['items', 'additionalCosts'])))->getData();
    }

    public function test_discount_appears_as_negative_line_and_reduces_grand_total(): void
    {
        $this->makeCost(); // +400.00
        $this->makeCost([
            'cost_type' => AdditionalCostType::DISCOUNT->value,
            'description' => 'Desconto de 10%',
            'amount' => 2_000_000, // normalizado para −200.00 pelo model
            'amount_in_document_currency' => 2_000_000,
        ]);

        $data = $this->statementData();

        $this->assertCount(2, $data['additional_costs']);

        $discountRow = collect($data['additional_costs'])->firstWhere('raw_amount', -2_000_000);
        $this->assertNotNull($discountRow, 'Linha do desconto (negativa) deve aparecer no statement.');
        $this->assertSame('Discount', $discountRow['type']);
        $this->assertStringContainsString('-200.00', $discountRow['amount']);

        // 1.000,00 + 400,00 − 200,00 = 1.200,00
        $this->assertStringContainsString('1,200.00', $data['totals']['pi_grand_total']);
    }

    public function test_grand_total_sums_only_visible_lines(): void
    {
        $this->makeCost(); // visível: +400.00
        $this->makeCost([
            'description' => 'Waived cost',
            'status' => AdditionalCostStatus::WAIVED->value,
        ]);
        $this->makeCost([
            'cost_type' => AdditionalCostType::COMMISSION->value,
            'description' => 'Embedded commission',
            'commission_mode' => CommissionType::EMBEDDED->value,
            'commission_rate' => 5,
        ]);

        $data = $this->statementData();

        // Waived fica fora das linhas E do total. Comissão entra qualquer que
        // seja o commission_mode: o GeneratePaymentScheduleAction cobra todo
        // custo billable_to=client sem olhar esse flag, então escondê-la aqui
        // deixava o total impresso menor do que o cronograma do próprio
        // documento (prod: PI-2026-00078).
        $this->assertCount(2, $data['additional_costs']);

        $descriptions = collect($data['additional_costs'])->pluck('description')->all();
        $this->assertContains('Embedded commission', $descriptions);
        $this->assertNotContains('Waived cost', $descriptions);

        // 1.000,00 + 400,00 + 400,00 = 1.800,00
        $this->assertStringContainsString('1,800.00', $data['totals']['pi_grand_total']);
    }

    public function test_statement_without_costs_has_empty_section(): void
    {
        $data = $this->statementData();

        $this->assertSame([], $data['additional_costs']);
        $this->assertStringContainsString('1,000.00', $data['totals']['pi_grand_total']);
    }
}
