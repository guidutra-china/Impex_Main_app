<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPayableCostSideTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function makeCost(array $overrides = []): AdditionalCost
    {
        return $this->pi->additionalCosts()->create(array_merge([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo development',
            'amount' => 5_000_000, // USD 500.00
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 5_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ], $overrides));
    }

    public function test_supplier_payable_columns_round_trip_and_side_detection(): void
    {
        $cost = $this->makeCost([
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 3_500_000, // USD 350.00
            'supplier_payable_currency_code' => 'USD',
            'supplier_payable_amount_in_document_currency' => 3_500_000,
            'supplier_payable_due_date' => '2026-08-15',
        ]);

        $cost->refresh();

        $this->assertSame(3_500_000, $cost->supplier_payable_amount);
        $this->assertSame('2026-08-15', $cost->supplier_payable_due_date->format('Y-m-d'));
        $this->assertNull($cost->supplier_payable_status);
        $this->assertTrue($cost->hasSupplierPayableSide());
    }

    public function test_side_is_inactive_without_amount_or_supplier_or_when_supplier_billable(): void
    {
        $noAmount = $this->makeCost(['supplier_company_id' => $this->supplier->id]);
        $this->assertFalse($noAmount->hasSupplierPayableSide());

        $noSupplier = $this->makeCost(['supplier_payable_amount' => 1_000_000]);
        $this->assertFalse($noSupplier->hasSupplierPayableSide());

        $supplierBillable = $this->makeCost([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'supplier_payable_amount' => 1_000_000,
        ]);
        $this->assertFalse($supplierBillable->hasSupplierPayableSide());
    }

    /** @param array<string, mixed> $overrides */
    private function makeScheduleItem(array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Test item',
            'percentage' => 0,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ], $overrides));
    }

    public function test_side_tag_scopes_filter_by_notes(): void
    {
        $plain = $this->makeScheduleItem(['label' => 'plain', 'notes' => null]);
        $forwarder = $this->makeScheduleItem([
            'label' => 'fwd',
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG.' freight',
        ]);
        $supplier = $this->makeScheduleItem([
            'label' => 'sup',
            'notes' => PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.' logo',
        ]);
        $untagged = $this->makeScheduleItem(['label' => 'untagged', 'notes' => 'balance note']);

        $without = PaymentScheduleItem::query()->withoutSideTags()->pluck('id');
        $this->assertTrue($without->contains($plain->id));
        $this->assertFalse($without->contains($forwarder->id));
        $this->assertFalse($without->contains($supplier->id));
        $this->assertTrue($without->contains($untagged->id));

        $tagged = PaymentScheduleItem::query()
            ->withSideTag(PaymentScheduleItem::SUPPLIER_PAYABLE_TAG)
            ->pluck('id');
        $this->assertSame([$supplier->id], $tagged->all());

        $fwdTagged = PaymentScheduleItem::query()
            ->withSideTag(PaymentScheduleItem::FORWARDER_PAYABLE_TAG)
            ->pluck('id');
        $this->assertSame([$forwarder->id], $fwdTagged->all());
    }
}
