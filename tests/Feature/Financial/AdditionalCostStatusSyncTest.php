<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalCostStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private Company $clientCompany;

    private Company $forwarderCompany;

    private AdditionalCost $cost;

    private PaymentScheduleItem $clientPsi;

    private PaymentScheduleItem $forwarderPsi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        $this->forwarderCompany = Company::create(['name' => 'Forwarder Co', 'status' => 'active']);
        $this->forwarderCompany->companyRoles()->create(['role' => CompanyRole::FORWARDER->value]);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-COST-SYNC',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
        ]);

        // Freight cost with BOTH client billable amount AND forwarder payable amount.
        $this->cost = AdditionalCost::create([
            'costable_type' => Shipment::class,
            'costable_id' => $this->shipment->id,
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Sea freight Shenzhen → Santos',
            'amount' => 10_000_000, // 1000.0000 minor units
            'currency_code' => 'USD',
            'amount_in_document_currency' => 10_000_000,
            'billable_to' => BillableTo::CLIENT,
            'forwarder_company_id' => $this->forwarderCompany->id,
            'forwarder_amount' => 7_000_000, // 700.0000
            'forwarder_currency_code' => 'USD',
            'forwarder_amount_in_document_currency' => 7_000_000,
            'cost_date' => '2026-04-15',
            'status' => AdditionalCostStatus::PENDING,
        ]);

        // Client-billable PSI (no [forwarder-payable] tag).
        $this->clientPsi = PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Freight client',
            'percentage' => 100,
            'amount' => 10_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-05-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $this->cost->id,
            'notes' => 'auto: client side',
        ]);

        // Forwarder-payable PSI tagged so the observer can route it.
        $this->forwarderPsi = PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Freight forwarder',
            'percentage' => 100,
            'amount' => 7_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-05-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $this->cost->id,
            'notes' => 'auto: [forwarder-payable]',
        ]);
    }

    public function test_paying_client_side_marks_only_client_status_paid(): void
    {
        $this->fullyAllocate($this->clientPsi);

        $this->cost->refresh();

        $this->assertSame(AdditionalCostStatus::PAID, $this->cost->status);
        $this->assertNotSame(AdditionalCostStatus::PAID, $this->cost->forwarder_status);
    }

    public function test_paying_forwarder_side_marks_only_forwarder_status_paid(): void
    {
        $this->fullyAllocate($this->forwarderPsi);

        $this->cost->refresh();

        $this->assertSame(AdditionalCostStatus::PAID, $this->cost->forwarder_status);
        $this->assertNotSame(AdditionalCostStatus::PAID, $this->cost->status);
    }

    public function test_paying_both_sides_marks_both_paid(): void
    {
        $this->fullyAllocate($this->clientPsi);
        $this->fullyAllocate($this->forwarderPsi);

        $this->cost->refresh();

        $this->assertSame(AdditionalCostStatus::PAID, $this->cost->status);
        $this->assertSame(AdditionalCostStatus::PAID, $this->cost->forwarder_status);
    }

    public function test_removing_allocation_reverts_status(): void
    {
        $allocation = $this->fullyAllocate($this->clientPsi);

        $this->cost->refresh();
        $this->assertSame(AdditionalCostStatus::PAID, $this->cost->status);

        $allocation->delete();

        $this->cost->refresh();
        $this->assertNotSame(AdditionalCostStatus::PAID, $this->cost->status);
    }

    public function test_cost_without_forwarder_psi_only_syncs_client_side(): void
    {
        $standaloneCost = AdditionalCost::create([
            'costable_type' => Shipment::class,
            'costable_id' => $this->shipment->id,
            'cost_type' => AdditionalCostType::CUSTOMS,
            'description' => 'Customs broker',
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => '2026-04-15',
            'status' => AdditionalCostStatus::PENDING,
        ]);

        $psi = PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Customs',
            'percentage' => 100,
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-05-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $standaloneCost->id,
            'notes' => 'auto',
        ]);

        $this->fullyAllocate($psi);

        $standaloneCost->refresh();
        $this->assertSame(AdditionalCostStatus::PAID, $standaloneCost->status);
        $this->assertNull($standaloneCost->forwarder_status);
    }

    private function fullyAllocate(PaymentScheduleItem $psi): PaymentAllocation
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->clientCompany->id,
            'amount' => $psi->amount,
            'currency_code' => $psi->currency_code,
            'payment_date' => '2026-05-02',
            'status' => PaymentStatus::APPROVED,
        ]);

        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $psi->amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $psi->amount,
        ]);
    }
}
