<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\CRM\Companies\Widgets\CompanyFinancialStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyFinancialStatementForwarderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The forwarder section of the company statement must also count the
     * [supplier-payable] leg a forwarder-only company carries on an "other"
     * cost (SH-41: export documents invoiced by the freight forwarder).
     */
    public function test_forwarder_statement_includes_supplier_leg_costs(): void
    {
        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $forwarder = Company::create(['name' => 'TAS Logistics', 'status' => 'active']);
        $forwarder->companyRoles()->create(['role' => 'forwarder']);

        $shipment = Shipment::create([
            'reference' => 'SHP-TEST-041',
            'company_id' => $client->id,
            'issue_date' => '2026-08-20',
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);

        $freight = $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT->value,
            'description' => 'Air freight',
            'amount' => 17_434_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 17_434_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
            'forwarder_company_id' => $forwarder->id,
            'forwarder_amount' => 17_050_000,
            'forwarder_currency_code' => 'USD',
        ]);
        PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Freight payable: TAS Logistics - Air freight',
            'percentage' => 0,
            'amount' => 17_050_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG,
            'source_type' => AdditionalCost::class,
            'source_id' => $freight->id,
        ]);

        $docs = $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Export documents',
            'amount' => 4_260_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 4_260_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
            'supplier_company_id' => $forwarder->id,
            'supplier_payable_amount' => 786_900,
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 786_900,
        ]);
        app(SyncSupplierPayableScheduleItemAction::class)->execute($docs, $shipment);

        $widget = new CompanyFinancialStatement;
        $widget->record = $forwarder;
        $data = (new \ReflectionMethod($widget, 'getViewData'))->invoke($widget);

        $section = collect($data['sections'])->firstWhere('type', 'forwarder');
        $this->assertNotNull($section);
        $this->assertCount(1, $section['rows']);

        // 1,705.00 forwarder leg + 78.69 supplier leg (Money scale 10000)
        $this->assertSame('1,783.69', $section['rows'][0]['total']);
        $this->assertSame('1,783.69', $section['rows'][0]['remaining']);
        $this->assertSame('1,783.69', $section['summary']['total_invoiced']);
    }
}
