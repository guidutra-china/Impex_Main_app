<?php

namespace Tests\Unit\CRM;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Services\Client360DataService;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused integration tests for Client360DataService.
 *
 * Validates the three structural guarantees the page relies on:
 *  1. Branch consolidation — matrix queries pull both matrix and branch data.
 *  2. Client isolation — data from other clients never leaks in.
 *  3. PO traversal — POs are correctly reached via the proforma invoice link.
 */
class Client360DataServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $matrix;
    private Company $branch;
    private Company $otherClient;
    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matrix = Company::create(['name' => 'ACME Matrix', 'status' => 'active']);
        $this->branch = Company::create([
            'name' => 'ACME Branch SP',
            'status' => 'active',
            'parent_company_id' => $this->matrix->id,
        ]);
        $this->otherClient = Company::create(['name' => 'Other Client', 'status' => 'active']);
        $this->supplier = Company::create(['name' => 'Ningbo Steel Co', 'status' => 'active']);
    }

    public function test_company_ids_consolidates_matrix_and_branches(): void
    {
        $service = new Client360DataService($this->matrix);

        $ids = $service->companyIds();

        $this->assertContains($this->matrix->id, $ids);
        $this->assertContains($this->branch->id, $ids);
        $this->assertNotContains($this->otherClient->id, $ids);
    }

    public function test_company_ids_for_branch_returns_only_itself(): void
    {
        $service = new Client360DataService($this->branch);

        $this->assertEquals([$this->branch->id], $service->companyIds());
    }

    public function test_inquiries_returns_matrix_and_branch_inquiries_only(): void
    {
        $matrixInquiry = $this->createInquiry($this->matrix, InquiryStatus::QUOTING);
        $branchInquiry = $this->createInquiry($this->branch, InquiryStatus::RECEIVED);
        $otherInquiry = $this->createInquiry($this->otherClient, InquiryStatus::QUOTING);

        $inquiries = (new Client360DataService($this->matrix))->inquiries();

        $ids = $inquiries->pluck('id')->all();
        $this->assertContains($matrixInquiry->id, $ids);
        $this->assertContains($branchInquiry->id, $ids);
        $this->assertNotContains($otherInquiry->id, $ids);
    }

    public function test_proforma_invoices_isolates_data_to_client(): void
    {
        $clientInquiry = $this->createInquiry($this->matrix, InquiryStatus::WON);
        $clientPi = $this->createProformaInvoice($this->matrix, $clientInquiry, 'USD');

        $otherInquiry = $this->createInquiry($this->otherClient, InquiryStatus::WON);
        $this->createProformaInvoice($this->otherClient, $otherInquiry, 'USD');

        $invoices = (new Client360DataService($this->matrix))->proformaInvoices();

        $this->assertCount(1, $invoices);
        $this->assertEquals($clientPi->id, $invoices->first()->id);
    }

    public function test_purchase_orders_are_reached_through_proforma_invoice_link(): void
    {
        $inquiry = $this->createInquiry($this->matrix, InquiryStatus::WON);
        $pi = $this->createProformaInvoice($this->matrix, $inquiry, 'USD');
        $po = $this->createPurchaseOrder($pi, $this->supplier, PurchaseOrderStatus::IN_PRODUCTION);

        // PO for an unrelated client must NOT come through.
        $otherInquiry = $this->createInquiry($this->otherClient, InquiryStatus::WON);
        $otherPi = $this->createProformaInvoice($this->otherClient, $otherInquiry, 'USD');
        $this->createPurchaseOrder($otherPi, $this->supplier, PurchaseOrderStatus::IN_PRODUCTION);

        $pos = (new Client360DataService($this->matrix))->purchaseOrders();

        $this->assertCount(1, $pos);
        $this->assertEquals($po->id, $pos->first()->id);
    }

    public function test_kpis_count_active_inquiries_excluding_terminal_states(): void
    {
        $this->createInquiry($this->matrix, InquiryStatus::RECEIVED);
        $this->createInquiry($this->matrix, InquiryStatus::QUOTING);
        $this->createInquiry($this->matrix, InquiryStatus::QUOTED);
        $this->createInquiry($this->matrix, InquiryStatus::WON);       // excluded
        $this->createInquiry($this->matrix, InquiryStatus::LOST);      // excluded
        $this->createInquiry($this->matrix, InquiryStatus::CANCELLED); // excluded

        $kpis = (new Client360DataService($this->matrix))->kpis();

        $this->assertEquals(3, $kpis->activeInquiriesCount);
    }

    public function test_financial_summary_groups_pending_receivables_by_currency(): void
    {
        $inquiry = $this->createInquiry($this->matrix, InquiryStatus::WON);
        $piUsd = $this->createProformaInvoice($this->matrix, $inquiry, 'USD');
        $piEur = $this->createProformaInvoice($this->matrix, $inquiry, 'EUR');

        // 4,500 USD pending + 12,000 USD overdue + 8,000 EUR pending
        $this->createPaymentScheduleItem($piUsd, 'USD', 45000000, PaymentScheduleStatus::PENDING);
        $this->createPaymentScheduleItem($piUsd, 'USD', 120000000, PaymentScheduleStatus::OVERDUE);
        $this->createPaymentScheduleItem($piEur, 'EUR', 80000000, PaymentScheduleStatus::PENDING);

        // Resolved items must be ignored.
        $this->createPaymentScheduleItem($piUsd, 'USD', 999000000, PaymentScheduleStatus::PAID);

        $summary = (new Client360DataService($this->matrix))->financialSummary();

        $this->assertEquals(165000000, $summary->receivablesByCurrency['USD']);
        $this->assertEquals(80000000, $summary->receivablesByCurrency['EUR']);
        $this->assertEquals(120000000, $summary->overdueReceivablesByCurrency['USD']);
        $this->assertArrayNotHasKey('EUR', $summary->overdueReceivablesByCurrency);
    }

    // === Test helpers (raw create — no factories exist for PO/Shipment/Payment) ===

    private function createInquiry(Company $client, InquiryStatus $status): Inquiry
    {
        return Inquiry::create([
            'reference' => 'INQ-' . uniqid(),
            'company_id' => $client->id,
            'status' => $status->value,
            'source' => 'email',
            'currency_code' => 'USD',
            'received_at' => now()->toDateString(),
        ]);
    }

    private function createProformaInvoice(Company $client, Inquiry $inquiry, string $currency): ProformaInvoice
    {
        return ProformaInvoice::create([
            'reference' => 'PI-' . uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => ProformaInvoiceStatus::SENT->value,
            'currency_code' => $currency,
            'issue_date' => now()->toDateString(),
        ]);
    }

    private function createPurchaseOrder(ProformaInvoice $pi, Company $supplier, PurchaseOrderStatus $status): PurchaseOrder
    {
        return PurchaseOrder::create([
            'reference' => 'PO-' . uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'status' => $status->value,
            'currency_code' => 'USD',
            'issue_date' => now()->toDateString(),
        ]);
    }

    private function createPaymentScheduleItem(
        ProformaInvoice $pi,
        string $currency,
        int $amountMinor,
        PaymentScheduleStatus $status,
    ): PaymentScheduleItem {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Test installment',
            'percentage' => 100,
            'amount' => $amountMinor,
            'currency_code' => $currency,
            'status' => $status->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 0,
        ]);
    }
}
