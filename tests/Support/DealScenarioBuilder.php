<?php

namespace Tests\Support;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;

/**
 * Tests-only fluent helper. Creates PI/PO/Shipment/Payment scenarios
 * persisted via Eloquent (no faking). All amounts are in minor units
 * (Money::SCALE = 10_000), e.g. $80_000 = 800_000_0 minor.
 */
class DealScenarioBuilder
{
    public Company $client;

    public ProformaInvoice $pi;

    /** @var list<PurchaseOrder> */
    public array $purchaseOrders = [];

    /** @var list<Shipment> */
    public array $shipments = [];

    public static function make(): self
    {
        return new self;
    }

    public function forClient(Company $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function withPi(
        string $reference = 'PI-TEST-001',
        string $currency = 'USD',
        int $totalMinor = 800_000_0,
        string $issueDate = '2026-03-15',
        ProformaInvoiceStatus $status = ProformaInvoiceStatus::CONFIRMED,
        int $itemCount = 1,
    ): self {
        $inquiry = Inquiry::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => $currency,
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => $reference,
            'client_reference' => null,
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => $currency,
            'issue_date' => $issueDate,
            'status' => $status,
            'created_by' => null,
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            ProformaInvoiceItem::create([
                'proforma_invoice_id' => $this->pi->id,
                'quantity' => 10,
                'unit_price' => (int) ($totalMinor / $itemCount / 10),
                'sort_order' => $i,
            ]);
        }

        $this->pi->load('items');

        return $this;
    }

    public function withReceipt(int $amountMinor, string $date = '2026-03-20', string $reference = 'PAG-001'): self
    {
        $schedule = $this->pi->paymentScheduleItems()->firstOrCreate(
            ['sort_order' => 1],
            [
                'label' => '100%',
                'percentage' => 100,
                'amount' => $this->pi->items->sum(fn ($i) => $i->quantity * $i->unit_price),
                'currency_code' => $this->pi->currency_code,
                'status' => PaymentScheduleStatus::DUE,
                'is_blocking' => false,
                'is_credit' => false,
            ]
        );

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amountMinor,
            'currency_code' => $this->pi->currency_code,
            'payment_date' => $date,
            'reference' => $reference,
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $schedule->id,
            'allocated_amount' => $amountMinor,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amountMinor,
            'created_at' => $date,
        ]);

        return $this;
    }

    public function withPo(
        Company $supplier,
        string $reference = 'PO-TEST-001',
        int $totalMinor = 350_000_0,
        int $paidMinor = 0,
        string $currency = 'USD',
        string $issueDate = '2026-03-16',
    ): self {
        $po = PurchaseOrder::create([
            'reference' => $reference,
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $supplier->id,
            'currency_code' => $currency,
            'issue_date' => $issueDate,
            'status' => PurchaseOrderStatus::CONFIRMED,
            'created_by' => null,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'quantity' => 10,
            // PurchaseOrderItem armazena custo em unit_cost (PoRow soma unit_cost).
            'unit_cost' => (int) ($totalMinor / 10),
            'sort_order' => 1,
        ]);

        $schedule = $po->paymentScheduleItems()->create([
            'label' => '100%',
            'percentage' => 100,
            'amount' => $totalMinor,
            'currency_code' => $currency,
            'status' => PaymentScheduleStatus::DUE,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        if ($paidMinor > 0) {
            $payment = Payment::create([
                'direction' => PaymentDirection::OUTBOUND,
                'company_id' => $supplier->id,
                'amount' => $paidMinor,
                'currency_code' => $currency,
                'payment_date' => $issueDate,
                'reference' => $reference.'-PAY',
                'status' => PaymentStatus::APPROVED,
            ]);
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $schedule->id,
                'allocated_amount' => $paidMinor,
                'exchange_rate' => 1.0,
                'allocated_amount_in_document_currency' => $paidMinor,
                'created_at' => $issueDate,
            ]);
        }

        $this->purchaseOrders[] = $po;

        return $this;
    }

    public function withShipment(
        string $reference = 'SHP-TEST-001',
        int $totalCostMinor = 80_000_0,
        int $paidMinor = 0,
        string $currency = 'USD',
        string $issueDate = '2026-03-18',
        float $myItemsWeight = 100.0,
        float $otherItemsWeight = 0.0,
    ): self {
        $shipment = Shipment::create([
            'reference' => $reference,
            'company_id' => $this->client->id,
            'issue_date' => $issueDate,
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => $currency,
            'created_by' => null,
        ]);

        // The Deal Breakdown report sources shipment COST from additionalCosts
        // (not the freight PSI — that mixes revenue stage-PSIs). Persist the
        // freight as an AdditionalCost so attribution has a cost basis.
        if ($totalCostMinor > 0) {
            $shipment->additionalCosts()->create([
                'cost_type' => AdditionalCostType::FREIGHT,
                'description' => 'Freight',
                'amount' => $totalCostMinor,
                'currency_code' => $currency,
                'exchange_rate' => 1,
                'amount_in_document_currency' => $totalCostMinor,
                'billable_to' => BillableTo::COMPANY,
                'status' => AdditionalCostStatus::PENDING,
                'cost_date' => $issueDate,
            ]);
        }

        foreach ($this->pi->items as $index => $piItem) {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'total_weight' => $myItemsWeight / max(1, $this->pi->items->count()),
                'total_volume' => 0,
                'sort_order' => $index + 1,
            ]);
        }

        if ($otherItemsWeight > 0) {
            $otherInquiry = Inquiry::factory()->create([
                'company_id' => $this->client->id,
                'currency_code' => $currency,
            ]);
            $other = ProformaInvoice::create([
                'reference' => $reference.'-OTHER-PI',
                'inquiry_id' => $otherInquiry->id,
                'company_id' => $this->client->id,
                'currency_code' => $currency,
                'issue_date' => $issueDate,
                'status' => ProformaInvoiceStatus::CONFIRMED,
                'created_by' => null,
            ]);
            $otherItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $other->id,
                'quantity' => 10,
                'unit_price' => 1000,
                'sort_order' => 1,
            ]);
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $otherItem->id,
                'quantity' => 10,
                'total_weight' => $otherItemsWeight,
                'total_volume' => 0,
                'sort_order' => 99,
            ]);
        }

        $schedule = $shipment->paymentScheduleItems()->create([
            'label' => 'Freight 100%',
            'percentage' => 100,
            'amount' => $totalCostMinor,
            'currency_code' => $currency,
            'status' => PaymentScheduleStatus::DUE,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        if ($paidMinor > 0) {
            $payment = Payment::create([
                'direction' => PaymentDirection::OUTBOUND,
                'company_id' => $this->client->id,
                'amount' => $paidMinor,
                'currency_code' => $currency,
                'payment_date' => $issueDate,
                'reference' => $reference.'-PAY',
                'status' => PaymentStatus::APPROVED,
            ]);
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $schedule->id,
                'allocated_amount' => $paidMinor,
                'exchange_rate' => 1.0,
                'allocated_amount_in_document_currency' => $paidMinor,
                'created_at' => $issueDate,
            ]);
        }

        $this->shipments[] = $shipment;

        return $this;
    }

    /**
     * Frete com repasse a forwarder: cobra-se do cliente (clientChargeMinor,
     * billable_to=client) e paga-se ao forwarder (forwarderCostMinor). Gera dois
     * fluxos de pagamento: INBOUND (reembolso do cliente) e OUTBOUND (pagamento ao
     * forwarder). Shipment com itens só da PI atual → atribuição 100%.
     */
    public function withForwarderFreightShipment(
        string $reference = 'SHP-FWD-001',
        int $clientChargeMinor = 120_000_0,
        int $forwarderCostMinor = 100_000_0,
        int $forwarderPaidMinor = 0,
        int $clientReimbursedMinor = 0,
        string $currency = 'USD',
        string $issueDate = '2026-03-18',
    ): self {
        $shipment = Shipment::create([
            'reference' => $reference,
            'company_id' => $this->client->id,
            'issue_date' => $issueDate,
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => $currency,
            'created_by' => null,
        ]);

        $shipment->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Freight (forwarder)',
            'amount' => $clientChargeMinor,
            'currency_code' => $currency,
            'exchange_rate' => 1,
            'amount_in_document_currency' => $clientChargeMinor,
            'billable_to' => BillableTo::CLIENT,
            'forwarder_amount' => $forwarderCostMinor,
            'forwarder_currency_code' => $currency,
            'forwarder_exchange_rate' => 1,
            'forwarder_amount_in_document_currency' => $forwarderCostMinor,
            'status' => AdditionalCostStatus::PENDING,
            'cost_date' => $issueDate,
        ]);

        foreach ($this->pi->items as $index => $piItem) {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'total_weight' => 100.0 / max(1, $this->pi->items->count()),
                'total_volume' => 0,
                'sort_order' => $index + 1,
            ]);
        }

        $clientPsi = $shipment->paymentScheduleItems()->create([
            'label' => 'Freight (client)',
            'percentage' => 100,
            'amount' => $clientChargeMinor,
            'currency_code' => $currency,
            'status' => PaymentScheduleStatus::DUE,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        $forwarderPsi = $shipment->paymentScheduleItems()->create([
            'label' => 'Freight (forwarder)',
            'percentage' => 100,
            'amount' => $forwarderCostMinor,
            'currency_code' => $currency,
            'status' => PaymentScheduleStatus::DUE,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 2,
            'notes' => '[forwarder-payable]',
        ]);

        if ($clientReimbursedMinor > 0) {
            $payment = Payment::create([
                'direction' => PaymentDirection::INBOUND,
                'company_id' => $this->client->id,
                'amount' => $clientReimbursedMinor,
                'currency_code' => $currency,
                'payment_date' => $issueDate,
                'reference' => $reference.'-CLIENT-PAY',
                'status' => PaymentStatus::APPROVED,
            ]);
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $clientPsi->id,
                'allocated_amount' => $clientReimbursedMinor,
                'exchange_rate' => 1.0,
                'allocated_amount_in_document_currency' => $clientReimbursedMinor,
                'created_at' => $issueDate,
            ]);
        }

        if ($forwarderPaidMinor > 0) {
            $payment = Payment::create([
                'direction' => PaymentDirection::OUTBOUND,
                'company_id' => $this->client->id,
                'amount' => $forwarderPaidMinor,
                'currency_code' => $currency,
                'payment_date' => $issueDate,
                'reference' => $reference.'-FWD-PAY',
                'status' => PaymentStatus::APPROVED,
            ]);
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'payment_schedule_item_id' => $forwarderPsi->id,
                'allocated_amount' => $forwarderPaidMinor,
                'exchange_rate' => 1.0,
                'allocated_amount_in_document_currency' => $forwarderPaidMinor,
                'created_at' => $issueDate,
            ]);
        }

        $this->shipments[] = $shipment;

        return $this;
    }

    /** Comissão lançada como AdditionalCost de tipo COMMISSION na PI. */
    public function withPiCommission(
        int $amountMinor,
        BillableTo $billable = BillableTo::CLIENT,
        string $currency = 'USD',
        string $date = '2026-03-16',
    ): self {
        $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::COMMISSION,
            'description' => 'Commission',
            'amount' => $amountMinor,
            'currency_code' => $currency,
            'exchange_rate' => 1,
            'amount_in_document_currency' => $amountMinor,
            'billable_to' => $billable,
            'status' => AdditionalCostStatus::PENDING,
            'cost_date' => $date,
        ]);

        return $this;
    }

    /** Quotation com comissão EMBUTIDA, vinculada à PI (subtotal = unitPrice × qty). */
    public function withEmbeddedCommissionQuotation(
        float $rate = 5.0,
        int $subtotalMinor = 1_000_000_0,
        string $currency = 'USD',
    ): self {
        $quotation = Quotation::create([
            'reference' => $this->pi->reference.'-Q',
            'company_id' => $this->client->id,
            'currency_code' => $currency,
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => $rate,
            'status' => QuotationStatus::DRAFT,
            'version' => 1,
            'created_by' => null,
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => \App\Domain\Catalog\Models\Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price' => $subtotalMinor,
            'sort_order' => 1,
        ]);

        $this->pi->quotations()->attach($quotation->id);

        return $this;
    }

    public function build(): self
    {
        $this->pi->refresh();

        return $this;
    }
}
