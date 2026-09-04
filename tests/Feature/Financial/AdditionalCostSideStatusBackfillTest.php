<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AdditionalCostSideStatus;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backfill da migração: lados forwarder/fornecedor gravados antes do seed
 * ficaram NULL até o primeiro pagamento. Preenche só o que está NULL a
 * partir da parcela correspondente; o que o reconcile já gravou fica.
 */
class AdditionalCostSideStatusBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private Company $party;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->party = Company::create(['name' => 'TAS Logistics', 'status' => 'active']);
        $this->shipment = Shipment::create([
            'reference' => 'SHP-BF-1',
            'company_id' => $client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeCost(array $overrides = []): AdditionalCost
    {
        return AdditionalCost::create(array_merge([
            'costable_type' => Shipment::class,
            'costable_id' => $this->shipment->id,
            'cost_type' => AdditionalCostType::OTHER,
            'description' => 'Cost',
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 1_000_000,
            'billable_to' => BillableTo::CLIENT,
            'status' => AdditionalCostStatus::PENDING,
        ], $overrides));
    }

    private function makePsi(AdditionalCost $cost, string $tag, PaymentScheduleStatus $status): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'leg',
            'percentage' => 0,
            'amount' => 500_000,
            'currency_code' => 'USD',
            'status' => $status,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'notes' => $tag,
        ]);
    }

    public function test_backfill_fills_only_null_side_columns_from_their_psi(): void
    {
        $forwarderDue = $this->makeCost([
            'cost_type' => AdditionalCostType::FREIGHT,
            'forwarder_company_id' => $this->party->id,
            'forwarder_amount' => 500_000,
            'forwarder_currency_code' => 'USD',
        ]);
        $this->makePsi($forwarderDue, PaymentScheduleItem::FORWARDER_PAYABLE_TAG, PaymentScheduleStatus::DUE);

        $supplierPaid = $this->makeCost([
            'supplier_company_id' => $this->party->id,
            'supplier_payable_amount' => 500_000,
            'supplier_payable_currency_code' => 'USD',
        ]);
        $this->makePsi($supplierPaid, PaymentScheduleItem::SUPPLIER_PAYABLE_TAG, PaymentScheduleStatus::PAID);

        // Reconcile already wrote PAID here even though the PSI reads DUE:
        // the backfill must not touch a non-NULL column.
        $alreadySet = $this->makeCost([
            'cost_type' => AdditionalCostType::FREIGHT,
            'forwarder_company_id' => $this->party->id,
            'forwarder_amount' => 500_000,
            'forwarder_currency_code' => 'USD',
            'forwarder_status' => AdditionalCostStatus::PAID,
        ]);
        $this->makePsi($alreadySet, PaymentScheduleItem::FORWARDER_PAYABLE_TAG, PaymentScheduleStatus::DUE);

        // Client-only cost: no side PSI, nothing to fill.
        $clientOnly = $this->makeCost();
        $this->makePsi($clientOnly, '', PaymentScheduleStatus::DUE);

        $changed = AdditionalCostSideStatus::backfillMissing();

        $this->assertSame(2, $changed);
        $this->assertSame(AdditionalCostStatus::INVOICED, $forwarderDue->fresh()->forwarder_status);
        $this->assertSame(AdditionalCostStatus::PAID, $supplierPaid->fresh()->supplier_payable_status);
        $this->assertSame(AdditionalCostStatus::PAID, $alreadySet->fresh()->forwarder_status);
        $this->assertNull($clientOnly->fresh()->forwarder_status);
        $this->assertNull($clientOnly->fresh()->supplier_payable_status);
        $this->assertSame(AdditionalCostStatus::PENDING, $clientOnly->fresh()->status, 'Lado cliente nunca é tocado pelo backfill.');

        // Idempotent.
        $this->assertSame(0, AdditionalCostSideStatus::backfillMissing());
    }
}
