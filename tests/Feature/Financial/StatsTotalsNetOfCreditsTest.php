<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use App\Filament\Resources\PurchaseOrders\Widgets\PurchaseOrderStats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A linha de total da tabela de parcelas (Financial Summary) deve ser líquida
 * de créditos/descontos, igual aos cards — não a soma bruta das parcelas.
 */
class StatsTotalsNetOfCreditsTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Credit Totals Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-CT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
            'status' => 'shipped',
        ]);
    }

    private function makeItem(Model $payable, int $amount, bool $isCredit = false): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => get_class($payable),
            'payable_id' => $payable->id,
            'label' => $isCredit ? 'Discount: test' : '100% — Order Date',
            'percentage' => $isCredit ? 0 : 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'status' => $isCredit ? PaymentScheduleStatus::DUE : PaymentScheduleStatus::PENDING,
            'is_credit' => $isCredit,
            'sort_order' => $isCredit ? 2 : 1,
        ]);
    }

    private function viewData(object $widget, Model $record): array
    {
        $widget->record = $record->fresh();

        $method = new \ReflectionMethod($widget, 'getViewData');

        return $method->invoke($widget);
    }

    public function test_po_schedule_totals_are_net_of_credits(): void
    {
        $supplier = Company::create(['name' => 'Credit Totals Supplier', 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $po = PurchaseOrder::create([
            'reference' => 'PO-CT-001',
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $supplier->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
        ]);

        $this->makeItem($po, 1_000_000);
        $this->makeItem($po, 50_000, isCredit: true);

        $data = $this->viewData(new PurchaseOrderStats, $po);

        // 100.00 gross − 5.00 credit = 95.00 net.
        $this->assertSame('95.00', $data['totals']['amount']);
        $this->assertSame('95.00', $data['totals']['remaining']);
    }

    public function test_pi_schedule_totals_are_net_of_credits(): void
    {
        $this->makeItem($this->pi, 2_000_000);
        $this->makeItem($this->pi, 200_000, isCredit: true);

        $data = $this->viewData(new ProformaInvoiceStats, $this->pi);

        // 200.00 gross − 20.00 credit = 180.00 net.
        $this->assertSame('180.00', $data['totals']['amount']);
        $this->assertSame('180.00', $data['totals']['remaining']);
    }
}
