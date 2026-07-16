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
 * Espelho admin do fix do Portal (PI-2026-00023): parcelas isentas (waived)
 * contam como quitadas nos Financial Summary de PI e PO do painel.
 */
class AdminStatsWaivedInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Waived Admin Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-WA-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-WA-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
            'status' => 'shipped',
        ]);
    }

    private function makeWaivedItem(Model $payable, int $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => get_class($payable),
            'payable_id' => $payable->id,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'status' => PaymentScheduleStatus::WAIVED,
            'sort_order' => 1,
        ]);
    }

    private function viewData(object $widget, Model $record): array
    {
        $widget->record = $record->fresh();

        $method = new \ReflectionMethod($widget, 'getViewData');

        return $method->invoke($widget);
    }

    public function test_admin_pi_stats_treat_waived_installment_as_settled(): void
    {
        $this->makeWaivedItem($this->pi, 100_000);

        $data = $this->viewData(new ProformaInvoiceStats, $this->pi);

        $this->assertSame(100, $data['progress']);
        $this->assertSame(0, $data['scheduleItems'][0]['remaining_raw']);

        $labels = array_column($data['cards'], 'label');
        $this->assertContains(__('widgets.document_summary.waived'), $labels);

        $remainingCard = collect($data['cards'])->firstWhere('label', __('widgets.document_summary.remaining'));
        $this->assertSame('USD 0.00', $remainingCard['value']);
    }

    public function test_admin_po_stats_treat_waived_installment_as_settled(): void
    {
        $supplier = Company::create(['name' => 'Waived Supplier', 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $po = PurchaseOrder::create([
            'reference' => 'PO-WA-001',
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $supplier->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
        ]);
        $this->makeWaivedItem($po, 80_000);

        $data = $this->viewData(new PurchaseOrderStats, $po);

        $this->assertSame(100, $data['progress']);
        $this->assertSame(0, $data['scheduleItems'][0]['remaining_raw']);

        $labels = array_column($data['cards'], 'label');
        $this->assertContains(__('widgets.document_summary.waived'), $labels);

        $remainingCard = collect($data['cards'])->firstWhere('label', __('widgets.document_summary.remaining'));
        $this->assertSame('USD 0.00', $remainingCard['value']);
    }
}
