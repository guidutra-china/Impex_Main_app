<?php

namespace Tests\Feature\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PI-2026-00023: a parcela foi isentada (waived) mas o Portal do Cliente
 * seguia exibindo a PI como não paga. Waived deve contar como quitada.
 */
class PortalPiWaivedInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Portal Waived Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PW-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-PW-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
            'status' => 'shipped',
        ]);
    }

    private function makeItem(int $amount, PaymentScheduleStatus $status, string $label): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => $label,
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'status' => $status,
            'sort_order' => $this->pi->paymentScheduleItems()->count() + 1,
        ]);
    }

    private function viewData(): array
    {
        $widget = new PortalProformaInvoiceStats;
        $widget->record = $this->pi->fresh();

        $method = new \ReflectionMethod($widget, 'getViewData');

        return $method->invoke($widget);
    }

    public function test_fully_waived_pi_shows_as_settled_with_waived_indication(): void
    {
        $this->makeItem(100_000, PaymentScheduleStatus::WAIVED, '100% — Order Date');

        $data = $this->viewData();

        $this->assertSame(100, $data['progress'], 'waived installment counts as settled');
        $this->assertSame(0, $data['totals']['remaining_raw'], 'no outstanding balance');
        $this->assertSame(0, $data['scheduleItems'][0]['remaining_raw'], 'waived row shows zero remaining');

        $labels = array_column($data['cards'], 'label');
        $this->assertContains(__('widgets.document_summary.waived'), $labels, 'waived card must be shown');

        $remainingCard = collect($data['cards'])->firstWhere('label', __('widgets.document_summary.remaining'));
        $this->assertSame('USD 0.00', $remainingCard['value']);
        $this->assertSame(__('widgets.document_summary.fully_paid'), $remainingCard['description']);
    }

    public function test_waived_installment_does_not_hide_genuinely_open_balance(): void
    {
        $this->makeItem(30_000, PaymentScheduleStatus::WAIVED, '30% — Order Date');
        $this->makeItem(70_000, PaymentScheduleStatus::DUE, '70% — Before Shipment');

        $data = $this->viewData();

        $this->assertSame(30, $data['progress']);
        $this->assertSame(70_000, $data['totals']['remaining_raw'], 'only the due installment stays outstanding');
    }
}
