# Extrato Financeiro do Embarque — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um PDF por embarque, enviável ao cliente, com mercadoria embarcada, custos repassados, cronograma de pagamento (parcela cheia da PI e fatia do embarque), pagamentos recebidos e saldo — publicado no menu Documents do `ShipmentResource`.

**Architecture:** Um `AbstractPdfTemplate` monta o payload em `getDocumentData()` (padrão de `PaymentStatementPdfTemplate`) e uma view Blade formata. O cálculo de "quanto deste embarque é de cada PI" não é reimplementado: o método privado `shipmentShareByDocument()` do `ShipmentPaymentSummaryService` vira público. O wiring é um quarto `ActionGroup` no header do embarque.

**Tech Stack:** Laravel 12, Filament 4, dompdf via `PdfGeneratorService`, PHPUnit.

Spec: `docs/superpowers/specs/2026-08-31-shipment-financial-statement-design.md`

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Domain/Financial/Services/ShipmentPaymentSummaryService.php` *(modificar)* | expor `clientShareByProformaInvoice()` e `CONDITION_ORDER` |
| `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php` *(criar)* | monta o payload do documento |
| `resources/views/pdf/shipment-financial-statement.blade.php` *(criar)* | layout |
| `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php` *(modificar)* | quarto ActionGroup |
| `app/Domain/Settings/DataTransferObjects/CompanySettings.php` *(modificar)* | `email_default_message_financial_statement` |
| `app/Filament/Pages/ManageCompanySettings.php` *(modificar)* | Textarea da mensagem padrão |
| `lang/{en,pt_BR,zh_CN}/forms.php` *(modificar)* | `forms.labels.financial_statement` |
| `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php` *(criar)* | payload |
| `tests/Feature/Shipments/ShipmentFinancialStatementActionsTest.php` *(criar)* | wiring |

---

### Task 1: Expor a fatia por PI no ShipmentPaymentSummaryService

**Files:**
- Modify: `app/Domain/Financial/Services/ShipmentPaymentSummaryService.php`
- Test: `tests/Feature/ShipmentPaymentSummaryServiceTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar ao fim de `tests/Feature/ShipmentPaymentSummaryServiceTest.php`, antes da última `}`:

```php
    public function test_client_share_by_proforma_invoice_returns_shipped_value_per_pi(): void
    {
        $shipment = $this->makeShipment();
        $piA = ProformaInvoice::factory()->create();
        $piB = ProformaInvoice::factory()->create();

        $itemA = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $piA->id,
            'quantity' => 100,
            'unit_price' => 10_000,
        ]);
        $itemB = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $piB->id,
            'quantity' => 10,
            'unit_price' => 5_000,
        ]);

        // Só metade da quantidade da PI A embarca.
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $itemA->id,
            'quantity' => 50,
            'sort_order' => 1,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $itemB->id,
            'quantity' => 10,
            'sort_order' => 2,
        ]);

        $shares = app(ShipmentPaymentSummaryService::class)->clientShareByProformaInvoice($shipment);

        $this->assertSame(500_000, $shares->get($piA->id), 'Fatia da PI A = 50 × 10.000.');
        $this->assertSame(50_000, $shares->get($piB->id), 'Fatia da PI B = 10 × 5.000.');
    }

    public function test_condition_order_is_publicly_readable(): void
    {
        $this->assertSame('order_date', ShipmentPaymentSummaryService::CONDITION_ORDER[0]);
        $this->assertContains('delivery_date', ShipmentPaymentSummaryService::CONDITION_ORDER);
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/ShipmentPaymentSummaryServiceTest.php --filter=client_share_by_proforma_invoice`
Expected: FAIL — `Call to protected/undefined method ... clientShareByProformaInvoice()`

- [ ] **Step 3: Implementar**

Em `ShipmentPaymentSummaryService`, trocar a declaração da const:

```php
    /** Display order: document-level events first, then the shipment lifecycle. */
    public const CONDITION_ORDER = [
```

E acrescentar o método público logo depois de `openClientRemainderByShipment()`:

```php
    /**
     * Valor deste embarque em cada PI que ele carrega (unidades menores),
     * precificado pelo unit_price da PI. É a chave de rateio das parcelas de
     * nível documento — e o subtotal de mercadoria do extrato do embarque.
     *
     * @return Collection<int, int> PI id => valor embarcado
     */
    public function clientShareByProformaInvoice(Shipment $shipment): Collection
    {
        return $this->shipmentShareByDocument($shipment, ProformaInvoice::class, null);
    }
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/ShipmentPaymentSummaryServiceTest.php`
Expected: PASS — todos os testes do arquivo.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Financial/Services/ShipmentPaymentSummaryService.php tests/Feature/ShipmentPaymentSummaryServiceTest.php
git commit -m "refactor(financial): fatia do embarque por PI vira API pública do summary service"
```

---

### Task 2: Template — cabeçalho, cliente e mercadoria embarcada

**Files:**
- Create: `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`
- Create: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`:

```php
<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentFinancialStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create(['name' => 'Daxion Trading']);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'SH-2026-00041',
        ], $overrides));
    }

    /**
     * Cria uma PI com um item e embarca $shippedQuantity dele.
     */
    private function ship(
        Shipment $shipment,
        ProformaInvoice $pi,
        int $quantity = 100,
        int $unitPrice = 10_000,
        ?int $shippedQuantity = null,
    ): ProformaInvoiceItem {
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Item',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $shippedQuantity ?? $quantity,
            'sort_order' => 1,
        ]);

        return $piItem;
    }

    private function data(Shipment $shipment): array
    {
        return (new ShipmentFinancialStatementPdfTemplate($shipment))->getData();
    }

    public function test_goods_section_has_one_row_per_proforma_invoice_with_shipped_value(): void
    {
        $shipment = $this->makeShipment();

        $piA = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00078',
            'client_reference' => 'Daxion - 4th order',
        ]);
        $piB = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00079',
        ]);

        $this->ship($shipment, $piA, quantity: 100, unitPrice: 10_000);
        $this->ship($shipment, $piB, quantity: 10, unitPrice: 5_000);

        $data = $this->data($shipment);

        $this->assertCount(2, $data['goods']);

        $rowA = collect($data['goods'])->firstWhere('reference', 'PI-2026-00078');
        $this->assertSame('Daxion - 4th order', $rowA['client_reference']);
        $this->assertSame(1_000_000, $rowA['raw_amount']);
        $this->assertTrue($rowA['in_totals']);

        $this->assertSame(1_050_000, $data['raw_goods_total']);
        $this->assertSame('Daxion Trading', $data['client']['name']);
        $this->assertSame('SH-2026-00041', $data['shipment']['reference']);
    }

    public function test_proforma_invoice_in_another_currency_is_flagged_out_of_totals(): void
    {
        $shipment = $this->makeShipment();

        $usd = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-USD',
        ]);
        $cny = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'CNY',
            'reference' => 'PI-CNY',
        ]);

        $this->ship($shipment, $usd, quantity: 10, unitPrice: 1_000);
        $this->ship($shipment, $cny, quantity: 10, unitPrice: 9_999);

        $data = $this->data($shipment);

        $foreign = collect($data['goods'])->firstWhere('reference', 'PI-CNY');
        $this->assertFalse($foreign['in_totals']);
        $this->assertSame('CNY', $foreign['currency_code']);
        $this->assertTrue($data['has_foreign_currency_pis']);

        $this->assertSame(10_000, $data['raw_goods_total'], 'A PI em CNY não pode entrar no subtotal em USD.');
    }
}
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: FAIL — `Class "App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate" not found`

- [ ] **Step 3: Criar o template com cabeçalho e mercadoria**

Criar `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`:

```php
<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Collection;

/**
 * Extrato financeiro do embarque — documento de CLIENTE.
 *
 * Nenhum número de custo nosso, custo landed ou margem pode entrar neste
 * payload. Análise interna é o widget LandedCostCalculator; este documento sai
 * para fora da empresa.
 *
 * Spec: docs/superpowers/specs/2026-08-31-shipment-financial-statement-design.md
 */
class ShipmentFinancialStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.shipment-financial-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Financial Statement';
    }

    public function getDocumentType(): string
    {
        return 'shipment_financial_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "FS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.proformaInvoice',
        ]);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $shares = app(ShipmentPaymentSummaryService::class)
            ->clientShareByProformaInvoice($shipment);

        $proformaInvoices = ProformaInvoice::query()
            ->whereIn('id', $shares->keys())
            ->orderBy('reference')
            ->get();

        $goods = $this->buildGoods($proformaInvoices, $shares, $currencyCode);
        $goodsTotal = (int) collect($goods)->where('in_totals', true)->sum('raw_amount');

        return [
            'shipment' => $this->buildShipmentBlock($shipment, $currencyCode),
            'client' => ['name' => $shipment->company?->name ?? '—'],
            'goods' => $goods,
            'goods_total' => $this->formatMoney($goodsTotal, $currencyCode, 2),
            'raw_goods_total' => $goodsTotal,
            'has_foreign_currency_pis' => collect($goods)->contains(fn (array $row) => ! $row['in_totals']),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShipmentBlock(Shipment $shipment, string $currencyCode): array
    {
        return [
            'reference' => $shipment->reference,
            'client_reference' => $shipment->client_reference ?: '—',
            'issue_date' => $this->formatDate($shipment->issue_date),
            'currency_code' => $currencyCode,
            'transport_mode' => $shipment->transport_mode?->getLabel() ?? '—',
            'incoterm' => $shipment->incoterm?->value ?? '—',
            'bl_number' => $shipment->bl_number ?: '—',
            'vessel' => $shipment->vessel_name ?: '—',
            'voyage' => $shipment->voyage_number ?: '—',
            'origin_port' => $shipment->origin_port ?: '—',
            'destination_port' => $shipment->destination_port ?: '—',
            'etd' => $this->formatDate($shipment->etd),
            'eta' => $this->formatDate($shipment->eta),
            'packages' => $shipment->total_packages !== null ? (string) $shipment->total_packages : '—',
            'gross_weight' => $shipment->total_gross_weight !== null
                ? number_format((float) $shipment->total_gross_weight, 3).' kg'
                : '—',
            'volume' => $shipment->total_volume !== null
                ? number_format((float) $shipment->total_volume, 4).' CBM'
                : '—',
        ];
    }

    /**
     * @param  Collection<int, ProformaInvoice>  $proformaInvoices
     * @param  Collection<int, int>  $shares
     * @return array<int, array<string, mixed>>
     */
    private function buildGoods(Collection $proformaInvoices, Collection $shares, string $currencyCode): array
    {
        return $proformaInvoices->values()->map(function (ProformaInvoice $pi, int $index) use ($shares, $currencyCode) {
            $amount = (int) $shares->get($pi->id, 0);
            $piCurrency = $pi->currency_code ?? $currencyCode;
            $inTotals = $piCurrency === $currencyCode;

            return [
                'index' => $index + 1,
                'reference' => $pi->reference,
                'client_reference' => $pi->client_reference ?: '—',
                'currency_code' => $piCurrency,
                'amount' => $this->formatMoney($amount, $piCurrency, 2),
                'raw_amount' => $amount,
                'in_totals' => $inTotals,
            ];
        })->all();
    }
}
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 2 testes.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): template do extrato financeiro com cabeçalho e mercadoria por PI"
```

---

### Task 3: Custos repassados ao cliente

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`
- Test: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar ao topo do arquivo de teste, junto dos outros `use`:

```php
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
```

E acrescentar o helper e o teste dentro da classe:

```php
    private function cost(Shipment $shipment, BillableTo $billableTo, int $amount, array $overrides = []): AdditionalCost
    {
        return AdditionalCost::create(array_merge([
            'costable_type' => Shipment::class,
            'costable_id' => $shipment->id,
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Air shipping cost',
            'amount' => $amount,
            'currency_code' => 'USD',
            'amount_in_document_currency' => $amount,
            'billable_to' => $billableTo,
            'cost_date' => '2026-08-28',
            'status' => AdditionalCostStatus::PENDING,
        ], $overrides));
    }

    public function test_only_client_billable_costs_are_listed(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 1_000);

        $this->cost($shipment, BillableTo::CLIENT, 17_434_000);
        $this->cost($shipment, BillableTo::COMPANY, 999_999, ['description' => 'Internal only']);
        $this->cost($shipment, BillableTo::SUPPLIER, 888_888, ['description' => 'Supplier repass']);

        $data = $this->data($shipment);

        $this->assertCount(1, $data['costs']);
        $this->assertSame('Air shipping cost', $data['costs'][0]['description']);
        $this->assertSame(17_434_000, $data['raw_costs_total']);

        $payload = json_encode($data);
        $this->assertStringNotContainsString('Internal only', $payload);
        $this->assertStringNotContainsString('Supplier repass', $payload);
    }

    public function test_waived_costs_are_not_listed(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 1_000);

        $this->cost($shipment, BillableTo::CLIENT, 500_000, [
            'description' => 'Waived charge',
            'status' => AdditionalCostStatus::WAIVED,
        ]);

        $data = $this->data($shipment);

        $this->assertSame([], $data['costs']);
        $this->assertSame(0, $data['raw_costs_total']);
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php --filter=costs`
Expected: FAIL — `Undefined array key "costs"`

- [ ] **Step 3: Implementar**

No template, acrescentar aos `use` do topo:

```php
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
```

Em `getDocumentData()`, acrescentar `'additionalCosts'` ao `loadMissing`:

```php
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.proformaInvoice',
            'additionalCosts',
        ]);
```

Depois de `$goodsTotal`, acrescentar:

```php
        $costs = $this->buildCosts($shipment, $currencyCode);
        $costsTotal = (int) collect($costs)->sum('raw_amount');
```

E no array de retorno, depois de `has_foreign_currency_pis`:

```php
            'costs' => $costs,
            'costs_total' => $this->formatMoney($costsTotal, $currencyCode, 2),
            'raw_costs_total' => $costsTotal,
```

E o método:

```php
    /**
     * Custos do embarque repassados ao cliente. Custos absorvidos pela empresa
     * ou repassados ao fornecedor jamais entram — é documento de cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCosts(Shipment $shipment, string $currencyCode): array
    {
        return $shipment->additionalCosts
            ->filter(fn (AdditionalCost $cost) => $cost->billable_to === BillableTo::CLIENT
                && $cost->status !== AdditionalCostStatus::WAIVED)
            ->sortBy('cost_date')
            ->values()
            ->map(function (AdditionalCost $cost, int $index) use ($currencyCode) {
                $amount = (int) ($cost->amount_in_document_currency ?? $cost->amount);

                return [
                    'index' => $index + 1,
                    'type' => $cost->cost_type->getEnglishLabel(),
                    'description' => $cost->description ?: '—',
                    'date' => $this->formatDate($cost->cost_date),
                    'amount' => $this->formatMoney($amount, $currencyCode, 2),
                    'raw_amount' => $amount,
                ];
            })
            ->all();
    }
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 4 testes.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): seção de custos repassados no extrato do embarque"
```

---

### Task 4: Cronograma de pagamento com parcela cheia e fatia

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`
- Test: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar aos `use` do teste:

```php
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Settings\Enums\CalculationBase;
use Database\Factories\PaymentScheduleItemFactory;
```

E os testes:

```php
    public function test_document_level_installment_shows_full_and_prorated_amounts(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00078',
        ]);

        // PI de 1.000.000; só metade embarca => fatia = 500.000.
        $this->ship($shipment, $pi, quantity: 100, unitPrice: 10_000, shippedQuantity: 50);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 1_000_000,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $data = $this->data($shipment);

        $this->assertCount(1, $data['schedule']);
        $row = $data['schedule'][0];

        $this->assertSame('PI-2026-00078', $row['document']);
        $this->assertSame(1_000_000, $row['raw_document_amount'], 'Coluna da parcela cheia na PI.');
        $this->assertSame(500_000, $row['raw_shipment_amount'], 'Coluna da fatia deste embarque.');
        $this->assertTrue($row['is_prorated']);
        $this->assertSame(50, $row['share_percent']);

        $this->assertSame(500_000, $data['raw_schedule_total'], 'O total soma a fatia, não a parcela cheia.');
    }

    public function test_ship_specific_installment_uses_the_same_value_in_both_columns(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'label' => '70% — Shipment Date',
            'percentage' => 70,
            'amount' => 70_000,
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $row = $this->data($shipment)['schedule'][0];

        $this->assertSame(70_000, $row['raw_document_amount']);
        $this->assertSame(70_000, $row['raw_shipment_amount']);
        $this->assertFalse($row['is_prorated']);
    }

    public function test_remaining_rows_and_forwarder_legs_are_excluded(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '70% — Shipment Date [remaining]',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $cost = $this->cost($shipment, BillableTo::CLIENT, 100_000);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Freight: Air shipping cost',
            'percentage' => 0,
            'amount' => 100_000,
            'due_condition' => null,
        ]);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Freight payable: Forwarder',
            'percentage' => 0,
            'amount' => 95_000,
            'due_condition' => null,
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG.' ',
        ]);

        $data = $this->data($shipment);

        $labels = collect($data['schedule'])->pluck('label')->all();
        $this->assertContains('Freight: Air shipping cost', $labels);
        $this->assertNotContains('70% — Shipment Date [remaining]', $labels);
        $this->assertNotContains('Freight payable: Forwarder', $labels);
        $this->assertSame(100_000, $data['raw_schedule_total']);
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php --filter=installment`
Expected: FAIL — `Undefined array key "schedule"`

- [ ] **Step 3: Implementar**

No template, acrescentar aos `use`:

```php
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Settings\Enums\CalculationBase;
```

Em `getDocumentData()`, depois de `$costsTotal`:

```php
        $scheduleItems = $this->collectScheduleItems($shipment, $shares);
        $schedule = $this->buildSchedule($scheduleItems, $shares, $currencyCode);
        $scheduleTotal = (int) collect($schedule)->sum('raw_shipment_amount');
```

E no retorno, depois de `raw_costs_total`:

```php
            'schedule' => $schedule,
            'schedule_total' => $this->formatMoney($scheduleTotal, $currencyCode, 2),
            'raw_schedule_total' => $scheduleTotal,
```

E os métodos:

```php
    /**
     * As parcelas que este embarque cobra do cliente, em três baldes:
     * ship-specific da PI, nível documento da PI (rateado) e custo repassado
     * do próprio embarque. Linhas [remaining] — condição de embarque sem
     * vínculo — são o saldo não embarcado e ficam fora.
     *
     * @param  Collection<int, int>  $shares
     * @return Collection<int, PaymentScheduleItem>
     */
    private function collectScheduleItems(Shipment $shipment, Collection $shares): Collection
    {
        $shipSpecific = PaymentScheduleItem::query()
            ->where('payable_type', ProformaInvoice::class)
            ->where('shipment_id', $shipment->id)
            ->whereNull('source_type')
            ->where('is_credit', false)
            ->get();

        $documentLevel = $shares->isEmpty()
            ? collect()
            : PaymentScheduleItem::query()
                ->where('payable_type', ProformaInvoice::class)
                ->whereIn('payable_id', $shares->keys())
                ->whereNull('shipment_id')
                ->whereNull('source_type')
                ->where('is_credit', false)
                ->whereIn('due_condition', CalculationBase::documentLevelValues())
                ->get();

        $shipmentCosts = PaymentScheduleItem::query()
            ->where('payable_type', Shipment::class)
            ->where('payable_id', $shipment->id)
            ->where('source_type', AdditionalCost::class)
            ->where('is_credit', false)
            ->withoutSideTags()
            ->whereIn(
                'source_id',
                AdditionalCost::query()
                    ->where('billable_to', BillableTo::CLIENT)
                    ->where('status', '!=', AdditionalCostStatus::WAIVED)
                    ->select('id'),
            )
            ->get();

        return $shipSpecific
            ->concat($documentLevel)
            ->concat($shipmentCosts)
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @param  Collection<int, int>  $shares
     * @return array<int, array<string, mixed>>
     */
    private function buildSchedule(Collection $items, Collection $shares, string $currencyCode): array
    {
        $references = ProformaInvoice::query()
            ->whereIn('id', $shares->keys())
            ->pluck('reference', 'id');

        return $items
            ->map(function (PaymentScheduleItem $item) use ($shares, $references, $currencyCode) {
                $documentAmount = (int) $item->amount;
                $isDocumentLevel = $item->payable_type === ProformaInvoice::class
                    && $item->shipment_id === null;

                if ($isDocumentLevel) {
                    $share = (int) $shares->get($item->payable_id, 0);
                    $shipmentAmount = $item->percentage
                        ? (int) round($share * $item->percentage / 100)
                        : 0;
                } else {
                    $shipmentAmount = $documentAmount;
                }

                if ($shipmentAmount <= 0) {
                    return null;
                }

                $ratio = $documentAmount > 0 ? $shipmentAmount / $documentAmount : 1;
                $paid = (int) round(min($item->paid_amount, $documentAmount) * $ratio);
                $balance = max(0, $shipmentAmount - $paid);

                return [
                    'label' => $item->label ?? '—',
                    'document' => $item->payable_type === ProformaInvoice::class
                        ? ($references->get($item->payable_id) ?? '—')
                        : '—',
                    'due_date' => $item->due_date ? $this->formatDate($item->due_date) : '—',
                    'raw_due_date' => $item->due_date?->toDateString(),
                    'condition' => $item->due_condition?->value,
                    'document_amount' => $this->formatMoney($documentAmount, $currencyCode, 2),
                    'raw_document_amount' => $documentAmount,
                    'shipment_amount' => $this->formatMoney($shipmentAmount, $currencyCode, 2),
                    'raw_shipment_amount' => $shipmentAmount,
                    'paid' => $this->formatMoney($paid, $currencyCode, 2),
                    'raw_paid' => $paid,
                    'balance' => $this->formatMoney($balance, $currencyCode, 2),
                    'raw_balance' => $balance,
                    'status' => $item->status->getEnglishLabel(),
                    'status_value' => $item->status->value,
                    'is_prorated' => $isDocumentLevel && $shipmentAmount !== $documentAmount,
                    'share_percent' => $documentAmount > 0
                        ? (int) round($shipmentAmount / $documentAmount * 100)
                        : 100,
                    'is_overdue' => $item->due_date?->isPast() === true
                        && ! $item->status->isResolved(),
                    'schedule_item_id' => $item->id,
                ];
            })
            ->filter()
            ->values()
            ->map(function (array $row, int $index) {
                return ['index' => $index + 1] + $row;
            })
            ->all();
    }
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 7 testes.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): cronograma do extrato com parcela cheia da PI e fatia do embarque"
```

---

### Task 5: Pagamentos recebidos

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`
- Test: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar aos `use` do teste:

```php
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
```

E o helper mais o teste:

```php
    private function allocate(PaymentScheduleItem $psi, int $amount, PaymentStatus $status): Payment
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-07',
            'status' => $status,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'allocated_amount_in_document_currency' => $amount,
        ]);

        return $payment;
    }

    public function test_only_approved_payments_are_listed(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);

        $psi = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'label' => '100% — Shipment Date',
            'percentage' => 100,
            'amount' => 100_000,
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $this->allocate($psi, 40_000, PaymentStatus::APPROVED);
        $this->allocate($psi, 25_000, PaymentStatus::PENDING_APPROVAL);

        $data = $this->data($shipment);

        $this->assertCount(1, $data['payments']);
        $this->assertSame('40,000.00', $data['payments'][0]['amount']);
        $this->assertSame('07/08/2026', $data['payments'][0]['date']);
        $this->assertSame('100% — Shipment Date', $data['payments'][0]['applied_to']);

        $this->assertSame(40_000, $data['schedule'][0]['raw_paid']);
        $this->assertSame(60_000, $data['schedule'][0]['raw_balance']);
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php --filter=approved_payments`
Expected: FAIL — `Undefined array key "payments"`

- [ ] **Step 3: Implementar**

No template, acrescentar aos `use`:

```php
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
```

Em `getDocumentData()`, depois de `$scheduleTotal`:

```php
        $payments = $this->buildPayments($scheduleItems->pluck('id'), $currencyCode);
        $paidTotal = (int) collect($payments)->sum('raw_amount');
```

E no retorno, depois de `raw_schedule_total`:

```php
            'payments' => $payments,
            'raw_paid_total' => $paidTotal,
```

E o método:

```php
    /**
     * Pagamentos em dinheiro já recebidos e alocados às parcelas deste
     * extrato. Alocações de crédito (credit_schedule_item_id preenchido) não
     * entram — Credit Notes estão fora do escopo deste documento.
     *
     * @param  Collection<int, int>  $scheduleItemIds
     * @return array<int, array<string, mixed>>
     */
    private function buildPayments(Collection $scheduleItemIds, string $currencyCode): array
    {
        if ($scheduleItemIds->isEmpty()) {
            return [];
        }

        return PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleItemIds)
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment.paymentMethod', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values()
            ->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
                $amount = (int) $allocation->allocated_amount_in_document_currency;

                return [
                    'index' => $index + 1,
                    'date' => $this->formatDate($allocation->payment->payment_date),
                    'reference' => $allocation->payment->reference ?: '—',
                    'method' => $allocation->payment->paymentMethod?->name ?? '—',
                    'applied_to' => $allocation->scheduleItem?->label ?? '—',
                    'amount' => $this->formatMoney($amount, $currencyCode, 2),
                    'raw_amount' => $amount,
                ];
            })
            ->all();
    }
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 8 testes.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): pagamentos recebidos no extrato do embarque"
```

---

### Task 6: Resumo por vencimento e totais

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php`
- Test: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar aos `use` do teste:

```php
use App\Domain\Financial\Enums\PaymentScheduleStatus;
```

E os testes:

```php
    public function test_summary_groups_by_condition_and_totals_use_the_shipment_slice(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 100, unitPrice: 10_000);

        $psi = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 1_000_000,
            'due_condition' => CalculationBase::ORDER_DATE,
            'due_date' => now()->subDays(10),
            'status' => PaymentScheduleStatus::OVERDUE,
        ]);
        $this->allocate($psi, 400_000, PaymentStatus::APPROVED);

        $freight = $this->cost($shipment, BillableTo::CLIENT, 200_000);
        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => $freight->id,
            'label' => 'Freight: Air shipping cost',
            'percentage' => 0,
            'amount' => 200_000,
            'due_condition' => null,
        ]);

        $data = $this->data($shipment);

        $orderDate = collect($data['summary_by_condition'])->firstWhere('condition', 'order_date');
        $this->assertSame(1_000_000, $orderDate['raw_amount']);
        $this->assertSame(400_000, $orderDate['raw_paid']);
        $this->assertSame(600_000, $orderDate['raw_balance']);

        $shipmentCosts = collect($data['summary_by_condition'])->firstWhere('condition', null);
        $this->assertSame('Shipment Costs', $shipmentCosts['label']);
        $this->assertSame(200_000, $shipmentCosts['raw_amount']);

        $this->assertSame(1_200_000, $data['totals']['raw_billed'], 'Mercadoria 1.000.000 + custos 200.000.');
        $this->assertSame(1_200_000, $data['totals']['raw_scheduled']);
        $this->assertSame(400_000, $data['totals']['raw_paid']);
        $this->assertSame(800_000, $data['totals']['raw_outstanding']);
        $this->assertSame(600_000, $data['totals']['raw_overdue']);
        $this->assertFalse($data['totals']['has_mismatch']);
    }

    public function test_mismatch_between_billed_and_scheduled_is_surfaced(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 100, unitPrice: 10_000);

        // Cronograma cobre só 30% do valor embarcado.
        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '30% — Order Date',
            'percentage' => 30,
            'amount' => 300_000,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $data = $this->data($shipment);

        $this->assertSame(1_000_000, $data['totals']['raw_billed']);
        $this->assertSame(300_000, $data['totals']['raw_scheduled']);
        $this->assertTrue($data['totals']['has_mismatch']);
    }

    public function test_payload_carries_no_cost_or_margin_figures(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->ship($shipment, $pi, quantity: 10, unitPrice: 10_000);
        $this->cost($shipment, BillableTo::COMPANY, 777_777, ['description' => 'Internal']);

        $payload = json_encode($this->data($shipment));

        foreach (['margin', 'landed', 'gross_profit', 'unit_cost', 'fob', 'forwarder'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $payload,
                "Documento de cliente não pode conter '{$forbidden}'.",
            );
        }
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php --filter=summary_groups`
Expected: FAIL — `Undefined array key "summary_by_condition"`

- [ ] **Step 3: Implementar**

No template, acrescentar aos `use`:

```php
use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
```

(já presente da Task 2 — não duplicar)

Em `getDocumentData()`, depois de `$paidTotal`:

```php
        $billedTotal = $goodsTotal + $costsTotal;
        $outstanding = max(0, $scheduleTotal - $paidTotal);
        $overdue = (int) collect($schedule)
            ->where('is_overdue', true)
            ->sum('raw_balance');
```

E no retorno, depois de `raw_paid_total`:

```php
            'summary_by_condition' => $this->buildSummaryByCondition($schedule, $currencyCode),
            'totals' => [
                'billed' => $this->formatMoney($billedTotal, $currencyCode, 2),
                'raw_billed' => $billedTotal,
                'scheduled' => $this->formatMoney($scheduleTotal, $currencyCode, 2),
                'raw_scheduled' => $scheduleTotal,
                'paid' => $this->formatMoney($paidTotal, $currencyCode, 2),
                'raw_paid' => $paidTotal,
                'outstanding' => $this->formatMoney($outstanding, $currencyCode, 2),
                'raw_outstanding' => $outstanding,
                'overdue' => $this->formatMoney($overdue, $currencyCode, 2),
                'raw_overdue' => $overdue,
                'has_overdue' => $overdue > 0,
                'has_mismatch' => $billedTotal !== $scheduleTotal,
            ],
```

E o método:

```php
    /**
     * Agrupa as parcelas do extrato por estágio de cobrança, na ordem de
     * exibição do summary service. Custos do embarque não têm estágio e caem
     * num grupo próprio ao final.
     *
     * @param  array<int, array<string, mixed>>  $schedule
     * @return array<int, array<string, mixed>>
     */
    private function buildSummaryByCondition(array $schedule, string $currencyCode): array
    {
        $order = ShipmentPaymentSummaryService::CONDITION_ORDER;

        return collect($schedule)
            ->groupBy(fn (array $row) => $row['condition'] ?? '')
            ->map(function (Collection $rows, string $condition) use ($currencyCode) {
                $amount = (int) $rows->sum('raw_shipment_amount');
                $paid = (int) $rows->sum('raw_paid');

                return [
                    'condition' => $condition === '' ? null : $condition,
                    'label' => $condition === ''
                        ? 'Shipment Costs'
                        : (CalculationBase::tryFrom($condition)?->getEnglishLabel() ?? $condition),
                    'amount' => $this->formatMoney($amount, $currencyCode, 2),
                    'raw_amount' => $amount,
                    'paid' => $this->formatMoney($paid, $currencyCode, 2),
                    'raw_paid' => $paid,
                    'balance' => $this->formatMoney(max(0, $amount - $paid), $currencyCode, 2),
                    'raw_balance' => max(0, $amount - $paid),
                ];
            })
            ->sortBy(function (array $group) use ($order) {
                $index = array_search($group['condition'], $order, true);

                return $index === false ? count($order) : $index;
            })
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 11 testes.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): resumo por vencimento e totais do extrato do embarque"
```

---

### Task 7: View Blade

**Files:**
- Create: `resources/views/pdf/shipment-financial-statement.blade.php`
- Test: `tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Acrescentar aos `use` do teste:

```php
use App\Domain\Infrastructure\Pdf\PdfRenderer;
```

E o teste:

```php
    public function test_pdf_renders_without_error(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'reference' => 'PI-2026-00078',
        ]);
        $this->ship($shipment, $pi, quantity: 100, unitPrice: 10_000, shippedQuantity: 50);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 1_000_000,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);
        $this->cost($shipment, BillableTo::CLIENT, 200_000);

        $template = new ShipmentFinancialStatementPdfTemplate($shipment);
        $html = view($template->getView(), $template->getData())->render();

        $this->assertStringContainsString('PI-2026-00078', $html);
        $this->assertStringContainsString('Air shipping cost', $html);
        $this->assertStringContainsString('Financial Statement', $html);
        $this->assertStringNotContainsString('Undefined', $html);
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php --filter=pdf_renders`
Expected: FAIL — `View [pdf.shipment-financial-statement] not found`

- [ ] **Step 3: Criar a view**

Criar `resources/views/pdf/shipment-financial-statement.blade.php`:

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
    .section-heading:first-child { margin-top: 0; }
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
    .currency-note { font-size: 6.5pt; color: #9ca3af; font-style: italic; }
    .prorated-note { font-size: 6.5pt; color: #6b7280; font-style: italic; }
    .logistics-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-bottom: 4px; }
    .logistics-table td { padding: 2px 6px 2px 0; vertical-align: top; }
    .logistics-table .k { color: #6b7280; width: 70px; }
    .logistics-table .v { color: #111827; font-weight: bold; }
    .subtotal-row td { font-weight: bold; border-top: 1px solid #d1d5db; padding-top: 4px; }
    .summary-box {
        margin-top: 12px;
        padding: 10px 14px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        background: #f9fafb;
    }
    .summary-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
    .summary-table td { padding: 3px 0; }
    .summary-table .label-cell { color: #6b7280; font-weight: bold; }
    .summary-table .value-cell { text-align: right; font-weight: bold; }
    .summary-table .paid-row td { color: #166534; }
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
    .mismatch-note {
        margin-top: 6px;
        font-size: 7pt;
        color: #92400e;
        font-style: italic;
    }
    .generated-at { margin-top: 20px; font-size: 7pt; color: #9ca3af; text-align: right; }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $shipment['reference'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['date'] }}</td>
            <td class="meta-value">{{ $shipment['issue_date'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $shipment['currency_code'] }}</td>
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
    {{-- === SHIPMENT === --}}
    <div class="section-heading">Shipment</div>
    <table class="logistics-table">
        <tr>
            <td class="k">B/L — AWB</td><td class="v">{{ $shipment['bl_number'] }}</td>
            <td class="k">Vessel / Flight</td><td class="v">{{ $shipment['vessel'] }} {{ $shipment['voyage'] }}</td>
            <td class="k">Incoterm</td><td class="v">{{ $shipment['incoterm'] }}</td>
        </tr>
        <tr>
            <td class="k">From</td><td class="v">{{ $shipment['origin_port'] }}</td>
            <td class="k">To</td><td class="v">{{ $shipment['destination_port'] }}</td>
            <td class="k">Mode</td><td class="v">{{ $shipment['transport_mode'] }}</td>
        </tr>
        <tr>
            <td class="k">ETD</td><td class="v">{{ $shipment['etd'] }}</td>
            <td class="k">ETA</td><td class="v">{{ $shipment['eta'] }}</td>
            <td class="k">Client Ref</td><td class="v">{{ $shipment['client_reference'] }}</td>
        </tr>
        <tr>
            <td class="k">Packages</td><td class="v">{{ $shipment['packages'] }}</td>
            <td class="k">Gross Weight</td><td class="v">{{ $shipment['gross_weight'] }}</td>
            <td class="k">Volume</td><td class="v">{{ $shipment['volume'] }}</td>
        </tr>
    </table>

    {{-- === GOODS === --}}
    @if(count($goods) > 0)
        <div class="section-heading">Shipped Goods</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th style="width: 130px;">Proforma Invoice</th>
                    <th>Your Reference</th>
                    <th class="text-right" style="width: 110px;">Shipped Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($goods as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>
                            {{ $row['client_reference'] }}
                            @unless($row['in_totals'])
                                <br><span class="currency-note">{{ $row['currency_code'] }} — not included in totals</span>
                            @endunless
                        </td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="3" class="text-right">Goods Subtotal</td>
                    <td class="text-right">{{ $shipment['currency_code'] }} {{ $goods_total }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- === CHARGES === --}}
    @if(count($costs) > 0)
        <div class="section-heading">Charges</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th style="width: 110px;">Type</th>
                    <th>Description</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th class="text-right" style="width: 110px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($costs as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['description'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td class="text-right">{{ $shipment['currency_code'] }} {{ $row['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="4" class="text-right">Charges Subtotal</td>
                    <td class="text-right">{{ $shipment['currency_code'] }} {{ $costs_total }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- === PAYMENT SCHEDULE === --}}
    @if(count($schedule) > 0)
        <div class="section-heading">Payment Schedule</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Installment</th>
                    <th style="width: 90px;">Document</th>
                    <th class="text-center" style="width: 60px;">Due Date</th>
                    <th class="text-right" style="width: 85px;">Full Amount</th>
                    <th class="text-right" style="width: 85px;">This Shipment</th>
                    <th class="text-right" style="width: 80px;">Paid</th>
                    <th class="text-right" style="width: 80px;">Balance</th>
                    <th class="text-center" style="width: 55px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($schedule as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>
                            {{ $row['label'] }}
                            @if($row['is_prorated'])
                                <br><span class="prorated-note">{{ $row['share_percent'] }}% of the document instalment</span>
                            @endif
                        </td>
                        <td>{{ $row['document'] }}</td>
                        <td class="text-center">{{ $row['due_date'] }}</td>
                        <td class="text-right">{{ $row['document_amount'] }}</td>
                        <td class="text-right">{{ $row['shipment_amount'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $row['status_value'] }}">{{ $row['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="5" class="text-right">Due for this shipment</td>
                    <td class="text-right">{{ $shipment['currency_code'] }} {{ $schedule_total }}</td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>
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
                    <th class="text-right" style="width: 90px;">Amount ({{ $shipment['currency_code'] }})</th>
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

    {{-- === SUMMARY BY STAGE === --}}
    @if(count($summary_by_condition) > 0)
        <div class="section-heading">Summary by Stage</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Stage</th>
                    <th class="text-right" style="width: 110px;">Amount</th>
                    <th class="text-right" style="width: 110px;">Paid</th>
                    <th class="text-right" style="width: 110px;">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary_by_condition as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === TOTALS === --}}
    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="label-cell">Billed for this shipment</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['billed'] }}</td>
            </tr>
            @if($totals['has_mismatch'])
                <tr>
                    <td class="label-cell">Covered by payment schedule</td>
                    <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['scheduled'] }}</td>
                </tr>
            @endif
            <tr class="paid-row">
                <td class="label-cell">Payments Received</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['paid'] }}</td>
            </tr>
            <tr class="outstanding-row">
                <td class="label-cell">Outstanding Balance</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['outstanding'] }}</td>
            </tr>
            @if($totals['has_overdue'])
                <tr class="overdue-row">
                    <td class="label-cell">Of which Overdue</td>
                    <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['overdue'] }}</td>
                </tr>
            @endif
        </table>
        @if($totals['has_mismatch'])
            <div class="mismatch-note">
                The payment schedule does not cover the full billed amount of this shipment.
            </div>
        @endif
    </div>

    <div class="generated-at">
        Generated on {{ $generated_at }}
    </div>
@endsection
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`
Expected: PASS — 12 testes.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/shipment-financial-statement.blade.php tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php
git commit -m "feat(shipments): view do extrato financeiro do embarque"
```

---

### Task 8: Wiring no menu Documents, settings de e-mail e traduções

**Files:**
- Modify: `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php`
- Modify: `app/Domain/Settings/DataTransferObjects/CompanySettings.php:37`
- Modify: `app/Filament/Pages/ManageCompanySettings.php:187`
- Modify: `lang/en/forms.php`, `lang/pt_BR/forms.php`, `lang/zh_CN/forms.php`
- Create: `tests/Feature/Shipments/ShipmentFinancialStatementActionsTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/Feature/Shipments/ShipmentFinancialStatementActionsTest.php`:

```php
<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentFinancialStatementActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    public function test_financial_statement_actions_are_registered_on_the_shipment_header(): void
    {
        $shipment = Shipment::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSuccessful()
            ->assertActionExists('generateFinancialStatementPdf')
            ->assertActionExists('downloadFinancialStatementPdf')
            ->assertActionExists('previewFinancialStatementPdf')
            ->assertActionExists('sendFinancialStatementByEmail');
    }

    public function test_company_settings_expose_the_financial_statement_email_message(): void
    {
        $this->assertTrue(
            property_exists(CompanySettings::class, 'email_default_message_financial_statement'),
            'SendDocumentByEmailAction usa property_exists; sem a propriedade a mensagem padrão some.',
        );
    }
}
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementActionsTest.php`
Expected: FAIL — `Failed asserting that an action with name [generateFinancialStatementPdf] exists`

- [ ] **Step 3a: Adicionar a propriedade em CompanySettings**

Em `app/Domain/Settings/DataTransferObjects/CompanySettings.php`, depois de
`public ?string $email_default_message_commercial_invoice;`:

```php
    public ?string $email_default_message_financial_statement;
```

- [ ] **Step 3b: Adicionar o Textarea em ManageCompanySettings**

Em `app/Filament/Pages/ManageCompanySettings.php`, depois do bloco
`email_default_message_commercial_invoice`:

```php
                                        Textarea::make('email_default_message_financial_statement')
                                            ->label('Financial Statement email message')
                                            ->rows(4)
                                            ->helperText('Available placeholders: {recipient_name}, {company_name}, {reference}, {document_name}')
                                            ->columnSpanFull(),
```

- [ ] **Step 3c: Adicionar as traduções**

`lang/en/forms.php`, na seção `labels`, junto de `'commercial_invoice'`:

```php
        'financial_statement' => 'Financial Statement',
```

`lang/pt_BR/forms.php`:

```php
        'financial_statement' => 'Extrato Financeiro',
```

`lang/zh_CN/forms.php`:

```php
        'financial_statement' => '财务对账单',
```

- [ ] **Step 3d: Registrar o ActionGroup**

Em `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php`,
acrescentar ao bloco de `use` do topo:

```php
use App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate;
```

E acrescentar como quarto elemento do `ActionGroup::make([...])` em
`documentsActionGroup()`, logo depois do grupo `proforma_invoice`:

```php
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: ShipmentFinancialStatementPdfTemplate::class,
                    label: 'Generate PDF',
                )->name('generateFinancialStatementPdf'),
                GeneratePdfAction::download(
                    documentType: 'shipment_financial_statement_pdf',
                    label: 'Download PDF',
                )->name('downloadFinancialStatementPdf'),
                GeneratePdfAction::preview(
                    templateClass: ShipmentFinancialStatementPdfTemplate::class,
                    label: 'Preview PDF',
                )->name('previewFinancialStatementPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'shipment_financial_statement_pdf',
                    settingsKey: 'email_default_message_financial_statement',
                    label: 'Send by Email',
                )->name('sendFinancialStatementByEmail'),
            ])
                ->label(__('forms.labels.financial_statement'))
                ->icon('heroicon-o-banknotes')
                ->color('gray'),
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test tests/Feature/Shipments/ShipmentFinancialStatementActionsTest.php`
Expected: PASS — 2 testes.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php artisan test`
Expected: PASS — nenhuma regressão.

- [ ] **Step 6: Pint e commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat(shipments): extrato financeiro no menu Documents do embarque"
```

---

## Verificação manual final

- [ ] Abrir `/panel/shipments/41` e conferir que **Documents → Financial Statement → Preview PDF** abre o documento do SH-2026-00041 com a PI-2026-00078, a parcela de USD 432.506,41 (fatia 100%, pois o embarque leva a PI inteira), os USD 215.138,00 pagos e os USD 217.368,41 em aberto, mais o frete e o custo extra na seção Charges.
- [ ] Conferir que nenhum valor de custo nosso ou margem aparece no PDF.
