# Payment Statement (por PI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PDF client-facing por Proforma Invoice com cronograma de parcelas, pagamentos recebidos, Debit Notes, créditos aplicados e Outstanding Balance — irmão do Cost Statement.

**Architecture:** Novo `PaymentStatementPdfTemplate` (padrão `AbstractPdfTemplate` → view Blade → dompdf via `PdfGeneratorService`), botão header no `PaymentScheduleRelationManager` da PI. Dados vêm dos PaymentScheduleItems não side-tagged da PI, das PaymentAllocations aprovadas e das DebitNotes vinculadas.

**Tech Stack:** Laravel 12, Filament 4, dompdf (barryvdh/laravel-dompdf), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-05-payment-statement-design.md`

**Regras do projeto:** não commitar sem o Gui pedir — os commits abaixo só executam se o Gui autorizar; caso contrário, deixar no working tree. Rodar `vendor/bin/pint --dirty --format agent` antes de finalizar.

---

## Fatos do domínio (leia antes de codar)

- Valores monetários são **inteiros em minor units**. Formatar com `$this->formatMoney($minor, $currency, 2)` do `AbstractPdfTemplate` ([app/Domain/Infrastructure/Pdf/Templates/AbstractPdfTemplate.php:154](../../app/Domain/Infrastructure/Pdf/Templates/AbstractPdfTemplate.php)).
- `PaymentScheduleItem` (PSI): morphMany `paymentScheduleItems()` na PI via trait `HasPaymentSchedule`. PSIs `[supplier-payable]`/`[forwarder-payable]` (tag no campo `notes`) são contas a pagar da Impex e moram na PI — **excluir com `withoutSideTags()`**. Statuses: `pending|due|paid|overdue|waived` (não existe cancelled). Accessors prontos: `paid_amount` (cash+crédito, só payments APPROVED), `credit_applied_amount`, `remaining_amount` (0 para is_credit).
- `PaymentAllocation`: `payment_schedule_item_id` (parcela sendo quitada), `credit_schedule_item_id` (NULL = pagamento cash; NOT NULL = aplicação de crédito vinda do PSI de crédito), `allocated_amount_in_document_currency` (minor units na moeda do documento).
- `Payment`: `payment_date`, `reference`, `status` (`PaymentStatus::APPROVED` conta), relação `paymentMethod`.
- `DebitNote`: `proforma_invoice_id`, `party_type` (`PartyType::CLIENT`), `status` (`DebitNoteStatus::CANCELLED` excluir), `total_amount`, `currency_code`, accessors `paid_amount`/`remaining_amount` (via PSIs das line items — não conflita com os PSIs da PI). DN **não** guarda valor convertido: DN em moeda ≠ PI é listada mas fica fora dos totais.

---

### Task 1: `PaymentStatementPdfTemplate` (dados) — TDD

**Files:**
- Create: `app/Domain/Infrastructure/Pdf/Templates/PaymentStatementPdfTemplate.php`
- Test: `tests/Feature/Financial/PaymentStatementPdfTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makePi(): ProformaInvoice
    {
        $company = Company::factory()->create();

        return ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'currency_code' => 'USD',
        ]);
    }

    private function makeScheduleItem(ProformaInvoice $pi, array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Deposit 30%',
            'percentage' => 30,
            'amount' => 30000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
            'due_date' => now()->addDays(10),
        ], $overrides));
    }

    private function makeApprovedPayment(Company $company, int $amount): Payment
    {
        return Payment::create([
            'direction' => PaymentDirection::INBOUND->value,
            'company_id' => $company->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->subDays(3),
            'reference' => 'WIRE-001',
            'status' => PaymentStatus::APPROVED->value,
        ]);
    }

    /** Extrai o payload da seção testada. */
    private function data(ProformaInvoice $pi): array
    {
        $template = new PaymentStatementPdfTemplate($pi->fresh());

        $method = new \ReflectionMethod($template, 'getDocumentData');

        return $method->invoke($template);
    }

    public function test_schedule_payments_and_summary(): void
    {
        $pi = $this->makePi();

        $deposit = $this->makeScheduleItem($pi);
        $balance = $this->makeScheduleItem($pi, [
            'label' => 'Balance 70%',
            'percentage' => 70,
            'amount' => 70000,
            'sort_order' => 2,
        ]);

        $payment = $this->makeApprovedPayment($pi->company, 30000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $deposit->id,
            'allocated_amount' => 30000,
            'allocated_amount_in_document_currency' => 30000,
        ]);

        $data = $this->data($pi);

        $this->assertCount(2, $data['schedule']);
        $this->assertSame('Deposit 30%', $data['schedule'][0]['label']);

        $this->assertCount(1, $data['payments']);
        $this->assertSame('WIRE-001', $data['payments'][0]['reference']);

        // 30.000 pagos de 100.000 → outstanding 70.000 (minor units)
        $this->assertSame(70000, $data['totals']['raw_outstanding']);
        $this->assertSame(30000, $data['totals']['raw_payments_received']);
    }

    public function test_supplier_payable_tagged_items_are_excluded(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi);
        $this->makeScheduleItem($pi, [
            'label' => 'Supplier side',
            'sort_order' => 2,
            'notes' => 'auto '.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG,
        ]);

        $data = $this->data($pi);

        $this->assertCount(1, $data['schedule']);
        $this->assertSame('Deposit 30%', $data['schedule'][0]['label']);
    }

    public function test_credit_application_is_listed_and_reduces_balance(): void
    {
        $pi = $this->makePi();
        $item = $this->makeScheduleItem($pi, ['amount' => 50000]);

        $creditItem = $this->makeScheduleItem($pi, [
            'label' => 'Credit CN-2026-001',
            'amount' => 10000,
            'is_credit' => true,
            'sort_order' => 3,
        ]);

        $payment = $this->makeApprovedPayment($pi->company, 0);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $item->id,
            'credit_schedule_item_id' => $creditItem->id,
            'allocated_amount' => 10000,
            'allocated_amount_in_document_currency' => 10000,
        ]);

        $data = $this->data($pi);

        // PSI de crédito não entra no cronograma
        $this->assertCount(1, $data['schedule']);
        // aplicação de crédito listada
        $this->assertCount(1, $data['credits']);
        $this->assertSame(10000, $data['totals']['raw_credits_applied']);
        // crédito não é "payment received"
        $this->assertCount(0, $data['payments']);
        $this->assertSame(40000, $data['totals']['raw_outstanding']);
    }

    public function test_debit_notes_included_cancelled_and_foreign_currency_handled(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi, [
            'amount' => 10000,
            'status' => PaymentScheduleStatus::PAID->value,
        ]);

        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 5000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::ISSUED->value,
            'issued_at' => now()->subDay(),
        ]);
        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 9999,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::CANCELLED->value,
            'issued_at' => now()->subDay(),
        ]);
        DebitNote::create([
            'company_id' => $pi->company_id,
            'party_type' => PartyType::CLIENT->value,
            'proforma_invoice_id' => $pi->id,
            'total_amount' => 8888,
            'currency_code' => 'BRL',
            'status' => DebitNoteStatus::ISSUED->value,
            'issued_at' => now()->subDay(),
        ]);

        $data = $this->data($pi);

        // cancelada fora; USD + BRL listadas
        $this->assertCount(2, $data['debit_notes']);

        $inTotals = collect($data['debit_notes'])->where('in_totals', true);
        $this->assertCount(1, $inTotals);

        // só a DN USD entra no outstanding (schedule 100% pago)
        $this->assertSame(5000, $data['totals']['raw_outstanding']);
    }

    public function test_waived_item_stays_out_of_outstanding(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi, [
            'amount' => 20000,
            'status' => PaymentScheduleStatus::WAIVED->value,
        ]);

        $data = $this->data($pi);

        $this->assertSame(0, $data['totals']['raw_outstanding']);
        $this->assertSame('Waived', $data['schedule'][0]['status']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php`
Expected: FAIL — `Class "App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate" not found`

- [ ] **Step 3: Implementar o template**

```php
<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class PaymentStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.payment-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Payment Statement';
    }

    public function getDocumentType(): string
    {
        return 'payment_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "PS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var ProformaInvoice $pi */
        $pi = $this->model;
        $pi->loadMissing(['company', 'items', 'additionalCosts']);

        $currencyCode = $pi->currency_code ?? 'USD';

        $scheduleItems = $pi->paymentScheduleItems()
            ->withoutSideTags()
            ->where('is_credit', false)
            ->with('paymentTermStage')
            ->orderBy('sort_order')
            ->get();

        $scheduleRows = $scheduleItems->values()->map(function (PaymentScheduleItem $item, int $index) use ($currencyCode) {
            $cashPaid = max(0, $item->paid_amount - $item->credit_applied_amount);

            return [
                'index' => $index + 1,
                'label' => $item->label ?? $item->paymentTermStage?->name ?? '—',
                'due_date' => $item->due_date ? $this->formatDate($item->due_date) : '—',
                'amount' => $this->formatMoney($item->amount, $currencyCode, 2),
                'paid' => $this->formatMoney($cashPaid, $currencyCode, 2),
                'credit_applied' => $this->formatMoney($item->credit_applied_amount, $currencyCode, 2),
                'balance' => $this->formatMoney($item->remaining_amount, $currencyCode, 2),
                'status' => $item->status->getEnglishLabel(),
                'status_value' => $item->status->value,
            ];
        });

        $scheduleIds = $scheduleItems->pluck('id');

        $cashAllocations = PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleIds)
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment.paymentMethod', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values();

        $paymentRows = $cashAllocations->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'date' => $this->formatDate($allocation->payment->payment_date),
                'reference' => $allocation->payment->reference ?? '—',
                'method' => $allocation->payment->paymentMethod?->name ?? '—',
                'applied_to' => $allocation->scheduleItem?->label ?? '—',
                'amount' => $this->formatMoney($allocation->allocated_amount_in_document_currency, $currencyCode, 2),
            ];
        });

        $creditAllocations = PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleIds)
            ->whereNotNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment', 'creditItem', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values();

        $creditRows = $creditAllocations->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'date' => $this->formatDate($allocation->payment->payment_date),
                'credit' => $allocation->creditItem?->label ?? '—',
                'applied_to' => $allocation->scheduleItem?->label ?? '—',
                'amount' => $this->formatMoney($allocation->allocated_amount_in_document_currency, $currencyCode, 2),
            ];
        });

        $debitNotes = $pi->debitNotes()
            ->where('party_type', PartyType::CLIENT)
            ->where('status', '!=', DebitNoteStatus::CANCELLED)
            ->orderBy('issued_at')
            ->get();

        $debitNoteRows = $debitNotes->values()->map(function ($dn, int $index) use ($currencyCode) {
            $inTotals = $dn->currency_code === $currencyCode;

            return [
                'index' => $index + 1,
                'reference' => $dn->reference,
                'issued_at' => $this->formatDate($dn->issued_at),
                'due_date' => $dn->due_date ? $this->formatDate($dn->due_date) : '—',
                'currency_code' => $dn->currency_code,
                'total' => $this->formatMoney($dn->total_amount, $dn->currency_code, 2),
                'paid' => $this->formatMoney($dn->paid_amount, $dn->currency_code, 2),
                'balance' => $this->formatMoney($dn->remaining_amount, $dn->currency_code, 2),
                'status' => ucwords(str_replace('_', ' ', $dn->status->value)),
                'status_value' => $dn->status->value,
                'in_totals' => $inTotals,
            ];
        });

        // --- Totais (minor units, moeda da PI) ---
        $piItemsTotal = (int) $pi->items->sum('line_total');
        $clientCostsTotal = (int) $pi->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->sum('amount_in_document_currency');
        $piGrandTotal = $piItemsTotal + $clientCostsTotal;

        $sameCurrencyDns = $debitNotes->where('currency_code', $currencyCode);
        $debitNotesTotal = (int) $sameCurrencyDns->sum('total_amount');
        $debitNotesOutstanding = (int) $sameCurrencyDns->sum(fn ($dn) => $dn->remaining_amount);

        $paymentsReceived = (int) $cashAllocations->sum('allocated_amount_in_document_currency')
            + (int) $sameCurrencyDns->sum(fn ($dn) => $dn->paid_amount);
        $creditsApplied = (int) $creditAllocations->sum('allocated_amount_in_document_currency');

        $nonWaived = $scheduleItems->where('status', '!=', PaymentScheduleStatus::WAIVED);
        $scheduleOutstanding = (int) $nonWaived->sum(fn (PaymentScheduleItem $item) => $item->remaining_amount);
        $overdueOutstanding = (int) $scheduleItems
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->sum(fn (PaymentScheduleItem $item) => $item->remaining_amount);

        $outstanding = $scheduleOutstanding + $debitNotesOutstanding;

        return [
            'pi' => [
                'reference' => $pi->reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'currency_code' => $currencyCode,
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
            ],
            'schedule' => $scheduleRows->toArray(),
            'payments' => $paymentRows->toArray(),
            'credits' => $creditRows->toArray(),
            'debit_notes' => $debitNoteRows->toArray(),
            'has_foreign_currency_dns' => $debitNoteRows->contains(fn ($row) => ! $row['in_totals']),
            'totals' => [
                'pi_grand_total' => $this->formatMoney($piGrandTotal, $currencyCode, 2),
                'debit_notes' => $this->formatMoney($debitNotesTotal, $currencyCode, 2),
                'has_debit_notes' => $debitNotesTotal > 0,
                'payments_received' => $this->formatMoney($paymentsReceived, $currencyCode, 2),
                'credits_applied' => $this->formatMoney($creditsApplied, $currencyCode, 2),
                'has_credits' => $creditsApplied > 0,
                'outstanding' => $this->formatMoney($outstanding, $currencyCode, 2),
                'has_outstanding' => $outstanding > 0,
                'overdue' => $this->formatMoney($overdueOutstanding, $currencyCode, 2),
                'has_overdue' => $overdueOutstanding > 0,
                'raw_payments_received' => $paymentsReceived,
                'raw_credits_applied' => $creditsApplied,
                'raw_outstanding' => $outstanding,
            ],
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit (somente com autorização do Gui)**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/PaymentStatementPdfTemplate.php tests/Feature/Financial/PaymentStatementPdfTest.php
git commit -m "feat(finance): PaymentStatementPdfTemplate — dados do extrato financeiro da PI"
```

---

### Task 2: View Blade `pdf.payment-statement`

**Files:**
- Create: `resources/views/pdf/payment-statement.blade.php`
- Test: `tests/Feature/Financial/PaymentStatementPdfTest.php` (adicionar 1 teste)

- [ ] **Step 1: Adicionar teste de render que falha**

Adicionar ao `PaymentStatementPdfTest`:

```php
    public function test_pdf_renders_via_generator_service(): void
    {
        $pi = $this->makePi();
        $this->makeScheduleItem($pi);

        $template = new PaymentStatementPdfTemplate($pi->fresh());

        $content = app(\App\Domain\Infrastructure\Pdf\PdfGeneratorService::class)->preview($template);

        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame("PS-{$pi->reference}.pdf", $template->getFilename());
    }
```

Nota: `PdfGeneratorService::__construct` recebe `PdfRenderer` e `DocumentService` — se o container não resolver sozinho, instanciar como o `AdditionalCostsRelationManager` faz: `new PdfGeneratorService(new PdfRenderer, new DocumentService)`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php --filter=test_pdf_renders_via_generator_service`
Expected: FAIL — `View [pdf.payment-statement] not found`

- [ ] **Step 3: Criar a view**

Mesma estrutura do `cost-statement.blade.php` (extends `pdf.layouts.document`):

```blade
@extends('pdf.layouts.document')

@section('extra-styles')
    .section-heading {
        font-size: 9pt;
        font-weight: bold;
        color: #374151;
        margin: 16px 0 6px 0;
        padding-bottom: 3px;
        border-bottom: 1px solid #e5e7eb;
    }
    .section-heading:first-child {
        margin-top: 0;
    }
    .status-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 6.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .status-paid { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-due { background: #dbeafe; color: #1e40af; }
    .status-overdue { background: #fee2e2; color: #b91c1c; }
    .status-waived { background: #f3f4f6; color: #6b7280; }
    .status-issued { background: #dbeafe; color: #1e40af; }
    .status-partially_paid { background: #fef3c7; color: #92400e; }
    .status-draft { background: #f3f4f6; color: #6b7280; }
    .currency-note {
        font-size: 6.5pt;
        color: #9ca3af;
        font-style: italic;
    }
    .summary-box {
        margin-top: 12px;
        padding: 10px 14px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        background: #f9fafb;
    }
    .summary-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
    }
    .summary-table td { padding: 3px 0; }
    .summary-table .label-cell { color: #6b7280; font-weight: bold; }
    .summary-table .value-cell { text-align: right; font-weight: bold; }
    .summary-table .outstanding-row td {
        font-size: 10pt;
        font-weight: bold;
        color: #111827;
        padding-top: 6px;
        border-top: 2px solid #374151;
    }
    .summary-table .overdue-row td {
        color: #dc2626;
        font-size: 9pt;
        padding-top: 5px;
        border-top: 1px solid #d1d5db;
    }
    .summary-table .paid-row td { color: #166534; }
    .generated-at {
        margin-top: 20px;
        font-size: 7pt;
        color: #9ca3af;
        text-align: right;
    }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">PI Reference</td>
            <td class="meta-value">{{ $pi['reference'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">PI Date</td>
            <td class="meta-value">{{ $pi['issue_date'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $pi['currency_code'] }}</td>
        </tr>
    </table>
@endsection

@section('client-info')
    <div class="client-section">
        <div class="client-box">
            <div class="client-label">{{ $labels['to'] }}</div>
            <div class="client-name">{{ $client['name'] }}</div>
        </div>
    </div>
@endsection

@section('content')
    {{-- === PAYMENT SCHEDULE === --}}
    @if(count($schedule) > 0)
        <div class="section-heading">Payment Schedule</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Installment</th>
                    <th class="text-center" style="width: 65px;">Due Date</th>
                    <th class="text-right" style="width: 80px;">Amount</th>
                    <th class="text-right" style="width: 80px;">Paid</th>
                    <th class="text-right" style="width: 80px;">Credit</th>
                    <th class="text-right" style="width: 80px;">Balance</th>
                    <th class="text-center" style="width: 60px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($schedule as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-center">{{ $row['due_date'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['credit_applied'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $row['status_value'] }}">{{ $row['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="text-align: center; padding: 30px 0; color: #9ca3af; font-size: 10pt;">
            No payment schedule for this Proforma Invoice.
        </div>
    @endif

    {{-- === PAYMENTS RECEIVED === --}}
    @if(count($payments) > 0)
        <div class="section-heading">Payments Received</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th>Reference</th>
                    <th style="width: 90px;">Method</th>
                    <th>Applied To</th>
                    <th class="text-right" style="width: 90px;">Amount ({{ $pi['currency_code'] }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['method'] }}</td>
                        <td>{{ $row['applied_to'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === DEBIT NOTES === --}}
    @if(count($debit_notes) > 0)
        <div class="section-heading">Debit Notes</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Reference</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th class="text-center" style="width: 65px;">Due Date</th>
                    <th class="text-right" style="width: 85px;">Total</th>
                    <th class="text-right" style="width: 85px;">Paid</th>
                    <th class="text-right" style="width: 85px;">Balance</th>
                    <th class="text-center" style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($debit_notes as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>
                            {{ $row['reference'] }}
                            @unless($row['in_totals'])
                                <br><span class="currency-note">{{ $row['currency_code'] }} — not included in totals</span>
                            @endunless
                        </td>
                        <td class="text-center">{{ $row['issued_at'] }}</td>
                        <td class="text-center">{{ $row['due_date'] }}</td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['total'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $row['status_value'] }}">{{ $row['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === CREDITS APPLIED === --}}
    @if(count($credits) > 0)
        <div class="section-heading">Credit Notes Applied</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th>Credit</th>
                    <th>Applied To</th>
                    <th class="text-right" style="width: 90px;">Amount ({{ $pi['currency_code'] }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach($credits as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td>{{ $row['credit'] }}</td>
                        <td>{{ $row['applied_to'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === SUMMARY === --}}
    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="label-cell">Proforma Invoice Total</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['pi_grand_total'] }}</td>
            </tr>
            @if($totals['has_debit_notes'])
                <tr>
                    <td class="label-cell">Debit Notes</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['debit_notes'] }}</td>
                </tr>
            @endif
            <tr class="paid-row">
                <td class="label-cell">Payments Received</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['payments_received'] }}</td>
            </tr>
            @if($totals['has_credits'])
                <tr class="paid-row">
                    <td class="label-cell">Credits Applied</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['credits_applied'] }}</td>
                </tr>
            @endif
            <tr class="outstanding-row">
                <td class="label-cell">Outstanding Balance</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['outstanding'] }}</td>
            </tr>
            @if($totals['has_overdue'])
                <tr class="overdue-row">
                    <td class="label-cell">Of which Overdue</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['overdue'] }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="generated-at">
        Generated on {{ $generated_at }}
    </div>
@endsection
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit (somente com autorização do Gui)**

```bash
git add resources/views/pdf/payment-statement.blade.php tests/Feature/Financial/PaymentStatementPdfTest.php
git commit -m "feat(finance): view PDF do Payment Statement da PI"
```

---

### Task 3: Botão no PaymentScheduleRelationManager + traduções

**Files:**
- Modify: `app/Filament/RelationManagers/PaymentScheduleRelationManager.php` (headerActions ~linha 170 + novo método)
- Modify: `lang/en/forms.php`, `lang/pt_BR/forms.php`, `lang/zh_CN/forms.php` (chave `labels.payment_statement`, ao lado de `cost_statement` — en linha ~173, pt_BR ~173, zh_CN ~162)
- Test: `tests/Feature/Financial/PaymentStatementPdfTest.php` (adicionar 1 teste)

- [ ] **Step 1: Adicionar teste Livewire que falha**

Adicionar ao `PaymentStatementPdfTest` (imports adicionais: `App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice`, `App\Filament\Resources\ProformaInvoices\RelationManagers\PaymentScheduleRelationManager`, `App\Models\User`, `Livewire\Livewire`):

```php
    public function test_payment_statement_action_visible_on_pi_schedule(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $pi = $this->makePi();
        $this->makeScheduleItem($pi);

        Livewire::test(PaymentScheduleRelationManager::class, [
            'ownerRecord' => $pi->fresh(),
            'pageClass' => EditProformaInvoice::class,
        ])
            ->assertSuccessful()
            ->assertActionVisible('paymentStatement');
    }
```

Nota: se `User::factory()` não tiver coluna `is_admin`, seguir o padrão de auth dos testes Filament existentes (ver `tests/Feature/ProformaInvoiceItemsListColumnsTest.php` e copiar o setup de usuário/permissões de lá).

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php --filter=test_payment_statement_action_visible`
Expected: FAIL — action `paymentStatement` does not exist

- [ ] **Step 3: Implementar o action e as traduções**

Em `app/Filament/RelationManagers/PaymentScheduleRelationManager.php`, adicionar imports:

```php
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
```

No `headerActions` (linha ~170):

```php
->headerActions([
    $this->generateScheduleAction(),
    $this->regenerateScheduleAction(),
    $this->paymentStatementAction(),
])
```

Novo método (mesmo shape do `costStatementAction()` do `AdditionalCostsRelationManager:886`):

```php
protected function paymentStatementAction(): Action
{
    return Action::make('paymentStatement')
        ->label(__('forms.labels.payment_statement'))
        ->icon('heroicon-o-banknotes')
        ->color('info')
        ->visible(fn () => $this->getOwnerRecord() instanceof ProformaInvoice
            && $this->getOwnerRecord()
                ->paymentScheduleItems()
                ->withoutSideTags()
                ->exists())
        ->action(function () {
            try {
                $template = new PaymentStatementPdfTemplate($this->getOwnerRecord());
                $service = new PdfGeneratorService(
                    new PdfRenderer,
                    new DocumentService,
                );

                $content = $service->preview($template);

                return response()->streamDownload(
                    function () use ($content) {
                        echo $content;
                    },
                    $template->getFilename(),
                    [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="'.$template->getFilename().'"',
                    ],
                );
            } catch (\Throwable $e) {
                report($e);

                Notification::make()
                    ->title('Payment Statement Generation Failed')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
            }
        });
}
```

(Conferir se `Action` e `Notification` já estão importados no arquivo; senão, adicionar `use Filament\Actions\Action;` e `use Filament\Notifications\Notification;`.)

Traduções — adicionar ao lado da chave `cost_statement` em cada arquivo:

```php
// lang/en/forms.php (labels)
'payment_statement' => 'Payment Statement',

// lang/pt_BR/forms.php (labels)
'payment_statement' => 'Extrato de Pagamentos',

// lang/zh_CN/forms.php (labels)
'payment_statement' => '付款对账单',
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit (somente com autorização do Gui)**

```bash
git add app/Filament/RelationManagers/PaymentScheduleRelationManager.php lang/en/forms.php lang/pt_BR/forms.php lang/zh_CN/forms.php tests/Feature/Financial/PaymentStatementPdfTest.php
git commit -m "feat(finance): botão Payment Statement no cronograma da PI"
```

---

### Task 4: Lint + regressão

- [ ] **Step 1: Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: sem erros (auto-corrige estilo)

- [ ] **Step 2: Testes relacionados**

Run: `php artisan test --compact tests/Feature/Financial/PaymentStatementPdfTest.php tests/Feature/Financial/ClientAccountsPayableTest.php tests/Feature/ShipmentPaymentSummaryServiceTest.php`
Expected: PASS

- [ ] **Step 3: Perguntar ao Gui se quer rodar a suíte completa** (`composer test`) e se autoriza os commits.

---

## Self-review (feito)

- Spec coberto: cronograma (T1), pagamentos (T1), DNs + moeda estrangeira (T1), créditos (T1), summary/outstanding/overdue (T1+T2), botão + i18n do label (T3), exclusão side-tags (T1), waived fora do outstanding (T1).
- Sem placeholders; tipos consistentes (`raw_*` em minor units para asserts, strings formatadas para a view).
- Riscos apontados nos passos: assinatura do `PdfGeneratorService` no container (T2) e setup de auth do teste Livewire (T3) — ambos com fallback documentado.
