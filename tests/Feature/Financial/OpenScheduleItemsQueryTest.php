<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenScheduleItemsQueryTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
    }

    public function test_receivables_include_open_pi_items_and_exclude_paid_and_credit(): void
    {
        // O payable precisa existir e não estar cancelado: a query exclui
        // parcelas de documentos cancelados/inexistentes do balance.
        $pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id]);
        $open = $this->psi(['payable_type' => ProformaInvoice::class, 'payable_id' => $pi->id, 'status' => PaymentScheduleStatus::DUE]);
        $paid = $this->psi(['payable_type' => ProformaInvoice::class, 'payable_id' => $pi->id, 'status' => PaymentScheduleStatus::PAID]);
        $credit = $this->psi(['payable_type' => ProformaInvoice::class, 'payable_id' => $pi->id, 'status' => PaymentScheduleStatus::PENDING, 'is_credit' => true]);

        $ids = OpenScheduleItemsQuery::receivables()->pluck('id');

        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($paid->id));
        $this->assertFalse($ids->contains($credit->id));
    }

    public function test_payables_include_open_po_and_supplier_dn_and_exclude_them_from_receivables(): void
    {
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id]);
        $poItem = $this->psi(['payable_type' => PurchaseOrder::class, 'payable_id' => $po->id, 'status' => PaymentScheduleStatus::DUE]);

        // Supplier debit note → issued → its PSI is a payable.
        $dn = DebitNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        DebitNoteLineItem::create(['debit_note_id' => $dn->id, 'description' => 'x', 'amount' => 1_000_000, 'currency_code' => 'USD']);
        app(IssueDebitNoteAction::class)->execute($dn);
        $dnItemId = PaymentScheduleItem::where('payable_type', DebitNote::class)->where('payable_id', $dn->id)->value('id');

        $payableIds = OpenScheduleItemsQuery::payables()->pluck('id');
        $receivableIds = OpenScheduleItemsQuery::receivables()->pluck('id');

        $this->assertTrue($payableIds->contains($poItem->id));
        $this->assertTrue($payableIds->contains($dnItemId));
        // No leakage across sides.
        $this->assertFalse($receivableIds->contains($poItem->id));
        $this->assertFalse($receivableIds->contains($dnItemId));
    }

    public function test_outbound_counterparty_options_include_forwarder_only_companies(): void
    {
        $forwarder = Company::create(['name' => 'TAS Logistics', 'status' => 'active']);
        $forwarder->companyRoles()->create(['role' => CompanyRole::FORWARDER->value]);

        $outbound = OpenScheduleItemsQuery::counterpartyOptions(PaymentDirection::OUTBOUND);
        $this->assertTrue($outbound->has($this->supplier->id));
        $this->assertTrue($outbound->has($forwarder->id), 'forwarder-only company missing from outbound counterparties');
        $this->assertFalse($outbound->has($this->client->id));

        $inbound = OpenScheduleItemsQuery::counterpartyOptions(PaymentDirection::INBOUND);
        $this->assertTrue($inbound->has($this->client->id));
        $this->assertFalse($inbound->has($forwarder->id));
    }

    public function test_client_dn_is_receivable_not_payable(): void
    {
        $dn = DebitNote::create([
            'company_id' => $this->client->id,
            'party_type' => PartyType::CLIENT,
            'total_amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        DebitNoteLineItem::create(['debit_note_id' => $dn->id, 'description' => 'lab', 'amount' => 1_000_000, 'currency_code' => 'USD']);
        app(IssueDebitNoteAction::class)->execute($dn);
        $dnItemId = PaymentScheduleItem::where('payable_type', DebitNote::class)->where('payable_id', $dn->id)->value('id');

        $this->assertTrue(OpenScheduleItemsQuery::receivables()->pluck('id')->contains($dnItemId));
        $this->assertFalse(OpenScheduleItemsQuery::payables()->pluck('id')->contains($dnItemId));
    }

    private function psi(array $attrs): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'is_credit' => false,
        ], $attrs));
    }
}
