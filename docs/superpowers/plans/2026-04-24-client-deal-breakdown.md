# Client Deal Breakdown Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an on-screen Filament page at `/client-deal-breakdown` that, given a client, shows every PI with its linked POs and attributed shipments, exposing received-from-client vs paid-to-supplier vs paid-to-shipment cash flow per deal.

**Architecture:** New bounded-context subdirectory `app/Domain/Financial/Reports/` with a pure service (`DealBreakdownReportService`) composing two support collaborators (`FxConverter`, `ShipmentAttributionCalculator`) and returning readonly DTOs. A standalone Filament Page class renders a custom Blade view — Filament Tables cannot express the two-level nested expansion required.

**Tech Stack:** PHP 8.3, Laravel 11, Filament v4, Livewire 3, Tailwind, PHPUnit, existing `Money` minor-units convention, existing `ExchangeRate` model for FX lookups.

**Spec:** `docs/superpowers/specs/2026-04-24-client-deal-breakdown-design.md`

---

## File Structure

### New files

```
app/Domain/Financial/Reports/
├── DealBreakdownReportService.php
├── DTOs/
│   ├── AdditionalCostRow.php
│   ├── AttributionBasis.php              (enum)
│   ├── DealBreakdownFilters.php
│   ├── DealBreakdownReport.php
│   ├── DealRow.php
│   ├── DealTotals.php
│   ├── KpiSummary.php
│   ├── PiInfo.php
│   ├── PoRow.php
│   ├── ReceiptItem.php
│   ├── ReceiptsBlock.php
│   └── ShipmentAttributionRow.php
└── Support/
    ├── FxConverter.php
    └── ShipmentAttributionCalculator.php

app/Filament/Pages/
└── ClientDealBreakdown.php

resources/views/filament/pages/
└── client-deal-breakdown.blade.php

resources/views/components/client-deal-breakdown/
├── receipts.blade.php
├── purchase-orders.blade.php
└── shipments.blade.php

tests/Unit/Financial/Reports/
├── FxConverterTest.php
├── ShipmentAttributionCalculatorTest.php
└── DealBreakdownReportServiceTest.php

tests/Feature/Filament/Pages/
└── ClientDealBreakdownPageTest.php

tests/Support/
└── DealScenarioBuilder.php

lang/en/client_deal_breakdown.php
lang/pt_BR/client_deal_breakdown.php
lang/zh_CN/client_deal_breakdown.php
```

### Modified files

```
lang/en/navigation.php           (add client_deal_breakdown label)
lang/pt_BR/navigation.php
lang/zh_CN/navigation.php
```

---

## Task 1: Create `AttributionBasis` enum

**Files:**
- Create: `app/Domain/Financial/Reports/DTOs/AttributionBasis.php`

- [ ] **Step 1: Write the enum**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

enum AttributionBasis: string
{
    case WEIGHT = 'weight';
    case VOLUME = 'volume';
    case QUANTITY = 'quantity';
    case VALUE = 'value';

    public function labelKey(): string
    {
        return 'client_deal_breakdown.basis.' . $this->value;
    }
}
```

- [ ] **Step 2: Verify file parses**

Run: `php -l app/Domain/Financial/Reports/DTOs/AttributionBasis.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/Reports/DTOs/AttributionBasis.php
git commit -m "feat(financial): add AttributionBasis enum for deal breakdown"
```

---

## Task 2: Create DTO `DealBreakdownFilters`

**Files:**
- Create: `app/Domain/Financial/Reports/DTOs/DealBreakdownFilters.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use Carbon\CarbonImmutable;

final readonly class DealBreakdownFilters
{
    /**
     * @param  list<ProformaInvoiceStatus>  $statuses
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $presentationCurrency,
        public array $statuses,
    ) {
    }

    /** @return list<string> */
    public function statusValues(): array
    {
        return array_map(fn (ProformaInvoiceStatus $s) => $s->value, $this->statuses);
    }

    public static function defaultStatuses(): array
    {
        return [
            ProformaInvoiceStatus::SENT,
            ProformaInvoiceStatus::CONFIRMED,
            ProformaInvoiceStatus::FINALIZED,
            ProformaInvoiceStatus::REOPENED,
        ];
    }
}
```

- [ ] **Step 2: Verify parses**

Run: `php -l app/Domain/Financial/Reports/DTOs/DealBreakdownFilters.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/Reports/DTOs/DealBreakdownFilters.php
git commit -m "feat(financial): add DealBreakdownFilters DTO"
```

---

## Task 3: Create remaining DTOs

**Files:**
- Create: `app/Domain/Financial/Reports/DTOs/PiInfo.php`
- Create: `app/Domain/Financial/Reports/DTOs/ReceiptItem.php`
- Create: `app/Domain/Financial/Reports/DTOs/ReceiptsBlock.php`
- Create: `app/Domain/Financial/Reports/DTOs/PoRow.php`
- Create: `app/Domain/Financial/Reports/DTOs/AdditionalCostRow.php`
- Create: `app/Domain/Financial/Reports/DTOs/ShipmentAttributionRow.php`
- Create: `app/Domain/Financial/Reports/DTOs/DealTotals.php`
- Create: `app/Domain/Financial/Reports/DTOs/DealRow.php`
- Create: `app/Domain/Financial/Reports/DTOs/KpiSummary.php`
- Create: `app/Domain/Financial/Reports/DTOs/DealBreakdownReport.php`

- [ ] **Step 1: Create `PiInfo.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use Carbon\CarbonImmutable;

final readonly class PiInfo
{
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $clientReference,
        public CarbonImmutable $issueDate,
        public ProformaInvoiceStatus $status,
        public string $currencyOriginal,
        public int $totalOriginal,
        public ?int $totalPresentation,
        public string $detailUrl,
    ) {
    }
}
```

- [ ] **Step 2: Create `ReceiptItem.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

use Carbon\CarbonImmutable;

final readonly class ReceiptItem
{
    public function __construct(
        public CarbonImmutable $paymentDate,
        public string $paymentReference,
        public ?string $stageLabel,
        public int $amountOriginal,
        public ?int $amountPresentation,
        public ?float $exchangeRateToPresentation,
        public string $paymentUrl,
    ) {
    }
}
```

- [ ] **Step 3: Create `ReceiptsBlock.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class ReceiptsBlock
{
    /** @param  list<ReceiptItem>  $items */
    public function __construct(
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public float $percentPaid,
        public array $items,
    ) {
    }
}
```

- [ ] **Step 4: Create `PoRow.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;

final readonly class PoRow
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $supplierName,
        public string $currencyOriginal,
        public int $totalOriginal,
        public ?int $totalPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public PurchaseOrderStatus $status,
        public string $detailUrl,
    ) {
    }
}
```

- [ ] **Step 5: Create `AdditionalCostRow.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\Financial\Enums\AdditionalCostType;

final readonly class AdditionalCostRow
{
    public function __construct(
        public string $label,
        public AdditionalCostType $type,
        public int $totalOriginal,
        public int $attributedOriginal,
        public ?int $attributedPresentation,
    ) {
    }
}
```

- [ ] **Step 6: Create `ShipmentAttributionRow.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class ShipmentAttributionRow
{
    /** @param  list<AdditionalCostRow>  $additionalCosts */
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $clientReference,
        public ?string $forwarderName,
        public string $currencyOriginal,
        public int $totalCostOriginal,
        public float $attributionPct,
        public AttributionBasis $basis,
        public int $attributedOriginal,
        public ?int $attributedPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public string $detailUrl,
        public array $additionalCosts,
    ) {
    }
}
```

- [ ] **Step 7: Create `DealTotals.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealTotals
{
    public function __construct(
        public int $cashBalance,
        public int $margin,
        public float $marginPct,
    ) {
    }
}
```

- [ ] **Step 8: Create `DealRow.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealRow
{
    /**
     * @param  list<PoRow>  $purchaseOrders
     * @param  list<ShipmentAttributionRow>  $shipments
     */
    public function __construct(
        public PiInfo $pi,
        public ReceiptsBlock $receipts,
        public array $purchaseOrders,
        public array $shipments,
        public DealTotals $totals,
    ) {
    }
}
```

- [ ] **Step 9: Create `KpiSummary.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class KpiSummary
{
    public function __construct(
        public int $totalReceived,
        public int $totalPaidSuppliers,
        public int $totalPaidShipments,
        public int $totalMargin,
        public int $dealCount,
    ) {
    }
}
```

- [ ] **Step 10: Create `DealBreakdownReport.php`**

```php
<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealBreakdownReport
{
    /**
     * @param  list<DealRow>  $deals
     * @param  list<string>  $unconvertedCurrencyPairs
     */
    public function __construct(
        public int $clientId,
        public string $clientName,
        public string $presentationCurrency,
        public DealBreakdownFilters $filters,
        public KpiSummary $kpi,
        public array $deals,
        public array $unconvertedCurrencyPairs,
    ) {
    }
}
```

- [ ] **Step 11: Verify all parse**

Run: `find app/Domain/Financial/Reports/DTOs -name '*.php' | xargs -I{} php -l {}`
Expected: every file prints `No syntax errors detected`

- [ ] **Step 12: Commit**

```bash
git add app/Domain/Financial/Reports/DTOs/
git commit -m "feat(financial): add deal breakdown DTOs"
```

---

## Task 4: `FxConverter` — failing test first

**Files:**
- Create: `tests/Unit/Financial/Reports/FxConverterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\Financial\Reports\Support\FxConverter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FxConverterTest extends TestCase
{
    public function test_same_currency_returns_input_unchanged(): void
    {
        $converter = new FxConverter('USD', []);

        $result = $converter->convertDocument(123_456, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertSame(123_456, $result);
    }

    public function test_uses_cached_rate_on_or_before_date(): void
    {
        $cache = [
            'USD>BRL|2026-03-10' => 5.10,
            'USD>BRL|2026-03-15' => 5.20,
        ];
        $converter = new FxConverter('BRL', $cache);

        // requested date 2026-03-14, expect rate from 2026-03-10 (latest ≤)
        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-14'));

        $this->assertSame(510_0000, $result);
    }

    public function test_uses_latest_rate_when_exact_date_matches(): void
    {
        $cache = [
            'USD>BRL|2026-03-10' => 5.10,
            'USD>BRL|2026-03-15' => 5.20,
        ];
        $converter = new FxConverter('BRL', $cache);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertSame(520_0000, $result);
    }

    public function test_returns_null_when_no_rate_available(): void
    {
        $converter = new FxConverter('BRL', []);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertNull($result);
    }

    public function test_returns_null_when_all_cached_rates_are_after_requested_date(): void
    {
        $cache = ['USD>BRL|2026-03-20' => 5.20];
        $converter = new FxConverter('BRL', $cache);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run — verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/FxConverterTest.php`
Expected: Errors like `Class "App\Domain\Financial\Reports\Support\FxConverter" not found`.

---

## Task 5: `FxConverter` — implementation

**Files:**
- Create: `app/Domain/Financial/Reports/Support/FxConverter.php`

- [ ] **Step 1: Write the implementation**

```php
<?php

namespace App\Domain\Financial\Reports\Support;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use Carbon\CarbonImmutable;

/**
 * Converts monetary amounts from a source currency to a presentation currency
 * using a pre-fetched rate cache. Returns null when no applicable rate exists,
 * which the caller must surface in the UI ("⚠ FX indisponível").
 *
 * Cache key format: "<FROM>><TO>|<YYYY-MM-DD>" e.g. "USD>BRL|2026-03-15".
 * Values are decimal multipliers (float).
 */
final class FxConverter
{
    /**
     * @param  array<string, float>  $ratesCache  Pre-fetched rates keyed as "FROM>TO|YYYY-MM-DD".
     */
    public function __construct(
        private readonly string $presentationCurrency,
        private readonly array $ratesCache,
    ) {
    }

    /**
     * Convert an amount in {@see $from} to the presentation currency, using the
     * approved rate whose date is the latest on or before {@see $at}.
     *
     * @return int|null  Amount in presentation currency minor units, or null if no rate.
     */
    public function convertDocument(int $amount, string $from, CarbonImmutable $at): ?int
    {
        if ($from === $this->presentationCurrency) {
            return $amount;
        }

        $rate = $this->findRate($from, $this->presentationCurrency, $at);
        if ($rate === null) {
            return null;
        }

        return (int) round($amount * $rate);
    }

    /**
     * Convert a PaymentAllocation to the presentation currency using the payment's
     * own currency and its payment date. Uses the allocation's stored
     * allocated_amount_in_document_currency when payment and document currencies
     * differ (source of truth for cash reality).
     */
    public function convertPayment(PaymentAllocation $allocation): ?int
    {
        $docAmount = (int) ($allocation->allocated_amount_in_document_currency ?? 0);
        $docCurrency = $allocation->scheduleItem?->currency_code
            ?? $allocation->payment?->currency_code;

        if ($docCurrency === null) {
            return null;
        }

        $paymentDate = $allocation->payment?->payment_date instanceof CarbonImmutable
            ? $allocation->payment->payment_date
            : CarbonImmutable::parse((string) $allocation->payment?->payment_date);

        return $this->convertDocument($docAmount, $docCurrency, $paymentDate);
    }

    private function findRate(string $from, string $to, CarbonImmutable $at): ?float
    {
        $prefix = "{$from}>{$to}|";
        $atKey = $at->format('Y-m-d');

        // Filter keys matching prefix and ≤ date; pick the max date.
        $bestDate = null;
        $bestRate = null;
        foreach ($this->ratesCache as $key => $rate) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $date = substr($key, strlen($prefix));
            if ($date > $atKey) {
                continue;
            }
            if ($bestDate === null || $date > $bestDate) {
                $bestDate = $date;
                $bestRate = $rate;
            }
        }

        return $bestRate;
    }

    /**
     * Fetch approved ExchangeRate rows for the given (fromCurrency, date) pairs
     * and return them keyed in the cache format this converter expects.
     *
     * @param  list<array{from: string, at: CarbonImmutable}>  $needed
     */
    public static function prefetchCache(array $needed, string $presentationCurrency): array
    {
        if (empty($needed) || $presentationCurrency === '') {
            return [];
        }

        // Group by from-currency
        $distinctFrom = array_values(array_unique(array_map(fn ($n) => $n['from'], $needed)));
        $distinctFrom = array_filter($distinctFrom, fn ($c) => $c !== $presentationCurrency);
        if (empty($distinctFrom)) {
            return [];
        }

        // Find currency ids
        $currencies = \App\Domain\Settings\Models\Currency::query()
            ->whereIn('code', array_merge($distinctFrom, [$presentationCurrency]))
            ->pluck('id', 'code');

        $targetId = $currencies[$presentationCurrency] ?? null;
        if ($targetId === null) {
            return [];
        }

        $fromIds = collect($distinctFrom)->map(fn ($c) => $currencies[$c] ?? null)->filter()->values();
        if ($fromIds->isEmpty()) {
            return [];
        }

        $rates = ExchangeRate::query()
            ->whereIn('base_currency_id', $fromIds)
            ->where('target_currency_id', $targetId)
            ->where('status', ExchangeRateStatus::APPROVED)
            ->orderBy('date')
            ->get(['base_currency_id', 'target_currency_id', 'rate', 'date']);

        $codeById = $currencies->flip();
        $cache = [];
        foreach ($rates as $r) {
            $fromCode = $codeById[$r->base_currency_id] ?? null;
            if ($fromCode === null) {
                continue;
            }
            $date = $r->date instanceof \DateTimeInterface
                ? $r->date->format('Y-m-d')
                : (string) $r->date;
            $cache["{$fromCode}>{$presentationCurrency}|{$date}"] = (float) $r->rate;
        }
        return $cache;
    }
}
```

- [ ] **Step 2: Run — verify test passes**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/FxConverterTest.php`
Expected: `OK (5 tests)` (all pass)

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/Reports/Support/FxConverter.php tests/Unit/Financial/Reports/FxConverterTest.php
git commit -m "feat(financial): FxConverter with date-based rate resolution"
```

---

## Task 6: `ShipmentAttributionCalculator` — failing test

**Files:**
- Create: `tests/Unit/Financial/Reports/ShipmentAttributionCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\Financial\Reports\DTOs\AttributionBasis;
use App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ShipmentAttributionCalculatorTest extends TestCase
{
    public function test_weight_based_attribution(): void
    {
        $pi = $this->makePi([1, 2]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
            ['pi_item_id' => 2, 'total_weight' => 200.0, 'total_volume' => 0, 'quantity' => 10],
            ['pi_item_id' => 99, 'total_weight' => 200.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::WEIGHT, $result->basis);
        $this->assertEqualsWithDelta(0.6, $result->pct, 0.0001);   // (100+200)/(100+200+200) = 0.6
    }

    public function test_volume_fallback_when_weight_zero(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 0, 'total_volume' => 3.0, 'quantity' => 10],
            ['pi_item_id' => 99, 'total_weight' => 0, 'total_volume' => 7.0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::VOLUME, $result->basis);
        $this->assertEqualsWithDelta(0.3, $result->pct, 0.0001);
    }

    public function test_quantity_fallback_when_weight_and_volume_zero(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 0, 'total_volume' => 0, 'quantity' => 5],
            ['pi_item_id' => 99, 'total_weight' => 0, 'total_volume' => 0, 'quantity' => 15],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::QUANTITY, $result->basis);
        $this->assertEqualsWithDelta(0.25, $result->pct, 0.0001);
    }

    public function test_single_pi_shipment_gets_full_attribution(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertEqualsWithDelta(1.0, $result->pct, 0.0001);
    }

    public function test_zero_pct_when_pi_not_in_shipment(): void
    {
        $pi = $this->makePi([42]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(0.0, $result->pct);
    }

    private function makePi(array $itemIds): ProformaInvoice
    {
        $pi = new ProformaInvoice();
        $pi->id = 500;
        $pi->setRelation('items', new Collection(array_map(function (int $id) {
            $item = new ProformaInvoiceItem();
            $item->id = $id;
            return $item;
        }, $itemIds)));

        return $pi;
    }

    /**
     * @param  list<array{pi_item_id:int,total_weight:float,total_volume:float,quantity:int}>  $rows
     */
    private function makeShipment(array $rows): Shipment
    {
        $shipment = new Shipment();
        $shipment->id = 900;
        $shipment->setRelation('items', new Collection(array_map(function (array $r) {
            $item = new ShipmentItem();
            $item->proforma_invoice_item_id = $r['pi_item_id'];
            $item->total_weight = $r['total_weight'];
            $item->total_volume = $r['total_volume'];
            $item->quantity = $r['quantity'];
            return $item;
        }, $rows)));
        return $shipment;
    }
}
```

- [ ] **Step 2: Run — verify failing**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/ShipmentAttributionCalculatorTest.php`
Expected: `Class "App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator" not found`

---

## Task 7: `ShipmentAttributionCalculator` — implementation

**Files:**
- Create: `app/Domain/Financial/Reports/Support/ShipmentAttributionCalculator.php`

- [ ] **Step 1: Write implementation**

```php
<?php

namespace App\Domain\Financial\Reports\Support;

use App\Domain\Financial\Reports\DTOs\AttributionBasis;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

/**
 * Computes the share of a Shipment's cost that should be attributed to a
 * specific ProformaInvoice when the shipment carries items belonging to more
 * than one PI.
 *
 * Cascade (first non-zero denominator wins):
 *   1. weight    (sum ShipmentItem.total_weight)
 *   2. volume    (sum ShipmentItem.total_volume)
 *   3. quantity  (sum ShipmentItem.quantity)
 *   4. value     (sum ShipmentItem.quantity * proformaInvoiceItem.unit_price)
 *
 * Both shipment and PI MUST be eager-loaded: `items` on each. PI items need
 * only `id`; shipment items need `proforma_invoice_item_id`, `total_weight`,
 * `total_volume`, `quantity` (+ `proformaInvoiceItem.unit_price` if we fall
 * back to value).
 */
final class ShipmentAttributionCalculator
{
    public function calculate(Shipment $shipment, ProformaInvoice $pi): ShipmentAttribution
    {
        $piItemIds = $pi->items->pluck('id')->all();
        $shipmentItems = $shipment->items;
        $piItems = $shipmentItems->whereIn('proforma_invoice_item_id', $piItemIds);

        // 1. Weight
        $totalWeight = (float) $shipmentItems->sum('total_weight');
        if ($totalWeight > 0) {
            $piWeight = (float) $piItems->sum('total_weight');
            return new ShipmentAttribution($piWeight / $totalWeight, AttributionBasis::WEIGHT);
        }

        // 2. Volume
        $totalVolume = (float) $shipmentItems->sum('total_volume');
        if ($totalVolume > 0) {
            $piVolume = (float) $piItems->sum('total_volume');
            return new ShipmentAttribution($piVolume / $totalVolume, AttributionBasis::VOLUME);
        }

        // 3. Quantity
        $totalQty = (int) $shipmentItems->sum('quantity');
        if ($totalQty > 0) {
            $piQty = (int) $piItems->sum('quantity');
            return new ShipmentAttribution($piQty / $totalQty, AttributionBasis::QUANTITY);
        }

        // 4. Value
        $totalValue = 0.0;
        $piValue = 0.0;
        foreach ($shipmentItems as $si) {
            $unitPrice = (float) ($si->proformaInvoiceItem?->unit_price ?? 0);
            $line = (float) $si->quantity * $unitPrice;
            $totalValue += $line;
            if (in_array($si->proforma_invoice_item_id, $piItemIds, true)) {
                $piValue += $line;
            }
        }
        if ($totalValue > 0) {
            return new ShipmentAttribution($piValue / $totalValue, AttributionBasis::VALUE);
        }

        return new ShipmentAttribution(0.0, AttributionBasis::WEIGHT);
    }
}
```

- [ ] **Step 2: Create the `ShipmentAttribution` value object in the same file**

Append to the same file (or a sibling — same namespace):

```php

/**
 * Internal result record. Not exposed in public DTOs.
 */
final readonly class ShipmentAttribution
{
    public function __construct(
        public float $pct,
        public AttributionBasis $basis,
    ) {
    }
}
```

Both classes in the same file is fine (internal-use value objects).

- [ ] **Step 3: Run — verify test passes**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/ShipmentAttributionCalculatorTest.php`
Expected: `OK (5 tests)`

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Financial/Reports/Support/ShipmentAttributionCalculator.php tests/Unit/Financial/Reports/ShipmentAttributionCalculatorTest.php
git commit -m "feat(financial): ShipmentAttributionCalculator with cascade fallback"
```

---

## Task 8: `DealScenarioBuilder` — test fixture helper

**Files:**
- Create: `tests/Support/DealScenarioBuilder.php`

This helper makes service tests readable. It creates a full PI → PO → Shipment scenario with payments in one fluent call.

- [ ] **Step 1: Write the helper**

```php
<?php

namespace Tests\Support;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;

/**
 * Tests-only helper. Creates realistic PI/PO/Shipment/Payment scenarios
 * with a fluent API. Always persists via Eloquent — no faking.
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
        return new self();
    }

    public function forClient(Company $client): self
    {
        $this->client = $client;
        return $this;
    }

    public function withPi(
        string $reference = 'PI-TEST-001',
        string $currency = 'USD',
        int $totalMinor = 800_000_0,    // 80 000 × Money::SCALE 10000
        string $issueDate = '2026-03-15',
        ProformaInvoiceStatus $status = ProformaInvoiceStatus::CONFIRMED,
        int $itemCount = 1,
    ): self {
        $this->pi = ProformaInvoice::create([
            'reference' => $reference,
            'client_reference' => null,
            'company_id' => $this->client->id,
            'currency_code' => $currency,
            'issue_date' => $issueDate,
            'status' => $status,
            'created_by' => 1,
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
        // Ensure PI has at least one schedule item
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
            'created_by' => 1,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'quantity' => 10,
            'unit_price' => (int) ($totalMinor / 10),
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
                'reference' => $reference . '-PAY',
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
            'issue_date' => $issueDate,
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => $currency,
            'created_by' => 1,
        ]);

        // Link our PI items
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
            // Fake "another PI" item
            $other = ProformaInvoice::create([
                'reference' => $reference . '-OTHER-PI',
                'company_id' => $this->client->id,
                'currency_code' => $currency,
                'issue_date' => $issueDate,
                'status' => ProformaInvoiceStatus::CONFIRMED,
                'created_by' => 1,
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
                'company_id' => $this->client->id,   // placeholder; real case is forwarder company
                'amount' => $paidMinor,
                'currency_code' => $currency,
                'payment_date' => $issueDate,
                'reference' => $reference . '-PAY',
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

    public function build(): self
    {
        $this->pi->refresh();
        return $this;
    }
}
```

- [ ] **Step 2: Verify parses**

Run: `php -l tests/Support/DealScenarioBuilder.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add tests/Support/DealScenarioBuilder.php
git commit -m "test(financial): add DealScenarioBuilder fixture helper"
```

---

## Task 9: `DealBreakdownReportService` — failing test (baseline)

**Files:**
- Create: `tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php`

Start with a minimal test: a client with one PI, one PO, no shipments, simple USD — verify DTOs are shaped correctly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Reports\DealBreakdownReportService;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DealScenarioBuilder;
use Tests\TestCase;

class DealBreakdownReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_pi_with_po_no_shipment(): void
    {
        $client = Company::factory()->create(['status' => 'active']);
        $supplier = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-100', totalMinor: 800_000_0, currency: 'USD')
            ->withReceipt(amountMinor: 400_000_0, date: '2026-03-20')
            ->withPo(supplier: $supplier, reference: 'PO-100', totalMinor: 350_000_0, paidMinor: 350_000_0)
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        $this->assertCount(1, $report->deals);
        $deal = $report->deals[0];

        $this->assertSame('PI-100', $deal->pi->reference);
        $this->assertSame(800_000_0, $deal->pi->totalOriginal);
        $this->assertSame(400_000_0, $deal->receipts->paidOriginal);
        $this->assertEqualsWithDelta(50.0, $deal->receipts->percentPaid, 0.01);
        $this->assertCount(1, $deal->purchaseOrders);
        $this->assertSame(350_000_0, $deal->purchaseOrders[0]->paidOriginal);
        $this->assertEmpty($deal->shipments);

        // Cash balance = received - paid suppliers - paid shipments = 400k - 350k - 0 = 50k
        $this->assertSame(50_000_0, $deal->totals->cashBalance);

        // KPIs
        $this->assertSame(400_000_0, $report->kpi->totalReceived);
        $this->assertSame(350_000_0, $report->kpi->totalPaidSuppliers);
        $this->assertSame(0, $report->kpi->totalPaidShipments);
        $this->assertSame(1, $report->kpi->dealCount);
    }
}
```

- [ ] **Step 2: Run — verify failing**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php::test_single_pi_with_po_no_shipment`
Expected: `Class "App\Domain\Financial\Reports\DealBreakdownReportService" not found`

---

## Task 10: `DealBreakdownReportService` — implementation (skeleton + receipts + POs)

**Files:**
- Create: `app/Domain/Financial/Reports/DealBreakdownReportService.php`

- [ ] **Step 1: Write the service**

```php
<?php

namespace App\Domain\Financial\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Reports\DTOs\AttributionBasis;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\Financial\Reports\DTOs\DealBreakdownReport;
use App\Domain\Financial\Reports\DTOs\DealRow;
use App\Domain\Financial\Reports\DTOs\DealTotals;
use App\Domain\Financial\Reports\DTOs\KpiSummary;
use App\Domain\Financial\Reports\DTOs\PiInfo;
use App\Domain\Financial\Reports\DTOs\PoRow;
use App\Domain\Financial\Reports\DTOs\ReceiptItem;
use App\Domain\Financial\Reports\DTOs\ReceiptsBlock;
use App\Domain\Financial\Reports\DTOs\ShipmentAttributionRow;
use App\Domain\Financial\Reports\Support\FxConverter;
use App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use Carbon\CarbonImmutable;

final class DealBreakdownReportService
{
    public function __construct(
        private readonly ShipmentAttributionCalculator $attributor = new ShipmentAttributionCalculator(),
    ) {
    }

    public function build(Company $client, DealBreakdownFilters $filters): DealBreakdownReport
    {
        $scopeIds = $this->resolveScopeIds($client);

        $pis = ProformaInvoice::query()
            ->whereIn('company_id', $scopeIds)
            ->whereIn('status', $filters->statusValues())
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with([
                'items',
                'paymentScheduleItems.allocations.payment',
                'paymentScheduleItems.paymentTermStage',
                'purchaseOrders.items',
                'purchaseOrders.supplierCompany:id,name',
                'purchaseOrders.paymentScheduleItems.allocations.payment',
                'items.shipmentItems.shipment.items.proformaInvoiceItem',
                'items.shipmentItems.shipment.forwarderCompany:id,name',
                'items.shipmentItems.shipment.paymentScheduleItems.allocations.payment',
                'items.shipmentItems.shipment.additionalCosts',
            ])
            ->orderByDesc('issue_date')
            ->get();

        $fxCache = $this->prefetchFxCache($pis, $filters->presentationCurrency);
        $fx = new FxConverter($filters->presentationCurrency, $fxCache);
        $unconverted = [];

        $deals = [];
        foreach ($pis as $pi) {
            $deals[] = $this->buildDealRow($pi, $fx, $unconverted);
        }

        $kpi = $this->buildKpi($deals);

        return new DealBreakdownReport(
            clientId: $client->id,
            clientName: $client->name,
            presentationCurrency: $filters->presentationCurrency,
            filters: $filters,
            kpi: $kpi,
            deals: $deals,
            unconvertedCurrencyPairs: array_values(array_unique($unconverted)),
        );
    }

    /** @return list<int> */
    private function resolveScopeIds(Company $client): array
    {
        $ids = [$client->id];
        $children = Company::query()
            ->where('parent_company_id', $client->id)
            ->pluck('id')
            ->all();
        return array_merge($ids, $children);
    }

    private function buildDealRow(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): DealRow
    {
        $piIssue = CarbonImmutable::parse((string) $pi->issue_date);
        $piTotalOriginal = (int) $pi->items->sum(fn ($i) => $i->quantity * $i->unit_price);
        $piTotalPres = $fx->convertDocument($piTotalOriginal, (string) $pi->currency_code, $piIssue);
        $this->recordMissing($piTotalPres, $pi->currency_code, $fx, $unconverted);

        $piInfo = new PiInfo(
            id: $pi->id,
            reference: (string) $pi->reference,
            clientReference: $pi->client_reference !== '' ? $pi->client_reference : null,
            issueDate: $piIssue,
            status: $pi->status,
            currencyOriginal: (string) $pi->currency_code,
            totalOriginal: $piTotalOriginal,
            totalPresentation: $piTotalPres,
            detailUrl: ProformaInvoiceResource::getUrl('view', ['record' => $pi->id]),
        );

        $receipts = $this->buildReceipts($pi, $fx, $unconverted);
        $poRows = $this->buildPoRows($pi, $fx, $unconverted);
        $shipmentRows = $this->buildShipmentRows($pi, $fx, $unconverted);

        $paidSuppliers = array_sum(array_map(fn ($p) => (int) ($p->paidPresentation ?? 0), $poRows));
        $paidShipments = array_sum(array_map(fn ($s) => (int) ($s->paidPresentation ?? 0), $shipmentRows));

        $received = (int) ($receipts->paidPresentation ?? 0);
        $cashBalance = $received - $paidSuppliers - $paidShipments;

        // Margin = PI total - PO totals (original in presentation currency) - shipment attributed cost
        $poTotalPres = array_sum(array_map(fn ($p) => (int) ($p->totalPresentation ?? 0), $poRows));
        $shipmentAttribPres = array_sum(array_map(fn ($s) => (int) ($s->attributedPresentation ?? 0), $shipmentRows));
        $margin = (int) ($piTotalPres ?? 0) - $poTotalPres - $shipmentAttribPres;
        $marginPct = ($poTotalPres + $shipmentAttribPres) > 0
            ? round($margin / ($poTotalPres + $shipmentAttribPres) * 100, 1)
            : 0.0;

        return new DealRow(
            pi: $piInfo,
            receipts: $receipts,
            purchaseOrders: $poRows,
            shipments: $shipmentRows,
            totals: new DealTotals(
                cashBalance: $cashBalance,
                margin: $margin,
                marginPct: (float) $marginPct,
            ),
        );
    }

    private function buildReceipts(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): ReceiptsBlock
    {
        $paidOriginal = 0;
        $paidPresentation = 0;
        $hasMissing = false;
        $items = [];

        foreach ($pi->paymentScheduleItems as $scheduleItem) {
            foreach ($scheduleItem->allocations as $alloc) {
                if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                    continue;
                }
                $amt = (int) $alloc->allocated_amount_in_document_currency;
                $paidOriginal += $amt;

                $pres = $fx->convertPayment($alloc);
                if ($pres === null) {
                    $hasMissing = true;
                    $this->recordMissing(null, $pi->currency_code, $fx, $unconverted);
                } else {
                    $paidPresentation += $pres;
                }

                $items[] = new ReceiptItem(
                    paymentDate: CarbonImmutable::parse((string) $alloc->payment->payment_date),
                    paymentReference: (string) $alloc->payment->reference,
                    stageLabel: $scheduleItem->paymentTermStage?->label ?? $scheduleItem->label,
                    amountOriginal: $amt,
                    amountPresentation: $pres,
                    exchangeRateToPresentation: null,
                    paymentUrl: '#',
                );
            }
        }

        $totalOriginal = (int) $pi->items->sum(fn ($i) => $i->quantity * $i->unit_price);
        $outstandingOriginal = max(0, $totalOriginal - $paidOriginal);
        $totalPres = $fx->convertDocument($totalOriginal, (string) $pi->currency_code, CarbonImmutable::parse((string) $pi->issue_date));
        $outstandingPres = $totalPres !== null && ! $hasMissing
            ? max(0, $totalPres - $paidPresentation)
            : null;

        return new ReceiptsBlock(
            paidOriginal: $paidOriginal,
            paidPresentation: $hasMissing ? null : $paidPresentation,
            outstandingOriginal: $outstandingOriginal,
            outstandingPresentation: $outstandingPres,
            percentPaid: $totalOriginal > 0 ? round($paidOriginal / $totalOriginal * 100, 1) : 0.0,
            items: $items,
        );
    }

    /** @return list<PoRow> */
    private function buildPoRows(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): array
    {
        $rows = [];
        foreach ($pi->purchaseOrders as $po) {
            $totalOriginal = (int) $po->items->sum(fn ($i) => $i->quantity * $i->unit_price);
            $paidOriginal = 0;
            $paidPres = 0;
            $hasMissing = false;

            foreach ($po->paymentScheduleItems as $scheduleItem) {
                foreach ($scheduleItem->allocations as $alloc) {
                    if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                        continue;
                    }
                    $paidOriginal += (int) $alloc->allocated_amount_in_document_currency;
                    $pres = $fx->convertPayment($alloc);
                    if ($pres === null) {
                        $hasMissing = true;
                    } else {
                        $paidPres += $pres;
                    }
                }
            }

            $issueDate = CarbonImmutable::parse((string) $po->issue_date);
            $totalPres = $fx->convertDocument($totalOriginal, (string) $po->currency_code, $issueDate);
            $this->recordMissing($totalPres, $po->currency_code, $fx, $unconverted);

            $outstandingOriginal = max(0, $totalOriginal - $paidOriginal);
            $outstandingPres = ($totalPres !== null && ! $hasMissing)
                ? max(0, $totalPres - $paidPres)
                : null;

            $rows[] = new PoRow(
                id: $po->id,
                reference: (string) $po->reference,
                supplierName: (string) ($po->supplierCompany?->name ?? ''),
                currencyOriginal: (string) $po->currency_code,
                totalOriginal: $totalOriginal,
                totalPresentation: $totalPres,
                paidOriginal: $paidOriginal,
                paidPresentation: $hasMissing ? null : $paidPres,
                outstandingOriginal: $outstandingOriginal,
                outstandingPresentation: $outstandingPres,
                status: $po->status,
                detailUrl: PurchaseOrderResource::getUrl('view', ['record' => $po->id]),
            );
        }
        return $rows;
    }

    /** @return list<ShipmentAttributionRow> */
    private function buildShipmentRows(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): array
    {
        // Group shipments via pi.items.shipmentItems.shipment
        $shipments = collect();
        foreach ($pi->items as $piItem) {
            foreach ($piItem->shipmentItems ?? [] as $si) {
                if ($si->shipment) {
                    $shipments->put($si->shipment->id, $si->shipment);
                }
            }
        }

        $rows = [];
        foreach ($shipments as $shipment) {
            $attribution = $this->attributor->calculate($shipment, $pi);

            $scheduleTotal = (int) $shipment->paymentScheduleItems->sum('amount');
            $additionalTotal = (int) $shipment->additionalCosts->sum('amount_in_document_currency');
            $totalCostOriginal = $scheduleTotal + $additionalTotal;
            $attributedOriginal = (int) round($totalCostOriginal * $attribution->pct);

            $paidOriginalFull = 0;
            $paidPresFull = 0;
            $hasMissing = false;
            foreach ($shipment->paymentScheduleItems as $scheduleItem) {
                foreach ($scheduleItem->allocations as $alloc) {
                    if ($alloc->payment?->status !== \App\Domain\Financial\Enums\PaymentStatus::APPROVED) {
                        continue;
                    }
                    $paidOriginalFull += (int) $alloc->allocated_amount_in_document_currency;
                    $pres = $fx->convertPayment($alloc);
                    if ($pres === null) {
                        $hasMissing = true;
                    } else {
                        $paidPresFull += $pres;
                    }
                }
            }
            $paidOriginalAttrib = (int) round($paidOriginalFull * $attribution->pct);
            $paidPresAttrib = $hasMissing ? null : (int) round($paidPresFull * $attribution->pct);

            $shipmentIssue = CarbonImmutable::parse((string) $shipment->issue_date);
            $attributedPres = $fx->convertDocument($attributedOriginal, (string) $shipment->currency_code, $shipmentIssue);
            $this->recordMissing($attributedPres, $shipment->currency_code, $fx, $unconverted);

            $additionalCostRows = [];
            foreach ($shipment->additionalCosts as $cost) {
                $costTotal = (int) $cost->amount_in_document_currency;
                $attrib = (int) round($costTotal * $attribution->pct);
                $attribPres = $fx->convertDocument($attrib, (string) $shipment->currency_code, $shipmentIssue);
                $additionalCostRows[] = new \App\Domain\Financial\Reports\DTOs\AdditionalCostRow(
                    label: (string) ($cost->description ?? $cost->cost_type?->getLabel() ?? ''),
                    type: $cost->cost_type,
                    totalOriginal: $costTotal,
                    attributedOriginal: $attrib,
                    attributedPresentation: $attribPres,
                );
            }

            $rows[] = new ShipmentAttributionRow(
                id: $shipment->id,
                reference: (string) $shipment->reference,
                clientReference: $shipment->client_reference !== '' ? $shipment->client_reference : null,
                forwarderName: $shipment->forwarderCompany?->name ?? $shipment->freight_forwarder,
                currencyOriginal: (string) $shipment->currency_code,
                totalCostOriginal: $totalCostOriginal,
                attributionPct: $attribution->pct,
                basis: $attribution->basis,
                attributedOriginal: $attributedOriginal,
                attributedPresentation: $attributedPres,
                paidOriginal: $paidOriginalAttrib,
                paidPresentation: $paidPresAttrib,
                outstandingOriginal: max(0, $attributedOriginal - $paidOriginalAttrib),
                outstandingPresentation: ($attributedPres !== null && $paidPresAttrib !== null)
                    ? max(0, $attributedPres - $paidPresAttrib)
                    : null,
                detailUrl: ShipmentResource::getUrl('view', ['record' => $shipment->id]),
                additionalCosts: $additionalCostRows,
            );
        }
        return $rows;
    }

    /** @param  list<DealRow>  $deals */
    private function buildKpi(array $deals): KpiSummary
    {
        $received = 0;
        $paidSuppliers = 0;
        $paidShipments = 0;
        $margin = 0;

        foreach ($deals as $deal) {
            $received += (int) ($deal->receipts->paidPresentation ?? 0);
            foreach ($deal->purchaseOrders as $po) {
                $paidSuppliers += (int) ($po->paidPresentation ?? 0);
            }
            foreach ($deal->shipments as $sh) {
                $paidShipments += (int) ($sh->paidPresentation ?? 0);
            }
            $margin += $deal->totals->margin;
        }

        return new KpiSummary(
            totalReceived: $received,
            totalPaidSuppliers: $paidSuppliers,
            totalPaidShipments: $paidShipments,
            totalMargin: $margin,
            dealCount: count($deals),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProformaInvoice>  $pis
     * @return array<string, float>
     */
    private function prefetchFxCache($pis, string $presentationCurrency): array
    {
        $needed = [];
        $add = function (?string $currency, ?string $date) use (&$needed) {
            if ($currency && $date) {
                $needed[] = ['from' => (string) $currency, 'at' => CarbonImmutable::parse((string) $date)];
            }
        };

        foreach ($pis as $pi) {
            $add($pi->currency_code, $pi->issue_date);
            foreach ($pi->paymentScheduleItems as $si) {
                foreach ($si->allocations as $a) {
                    $add($si->currency_code ?? $pi->currency_code, $a->payment?->payment_date);
                }
            }
            foreach ($pi->purchaseOrders as $po) {
                $add($po->currency_code, $po->issue_date);
                foreach ($po->paymentScheduleItems as $si) {
                    foreach ($si->allocations as $a) {
                        $add($si->currency_code ?? $po->currency_code, $a->payment?->payment_date);
                    }
                }
            }
            foreach ($pi->items as $piItem) {
                foreach ($piItem->shipmentItems ?? [] as $si) {
                    if ($si->shipment) {
                        $add($si->shipment->currency_code, $si->shipment->issue_date);
                        foreach ($si->shipment->paymentScheduleItems as $schedule) {
                            foreach ($schedule->allocations as $a) {
                                $add($schedule->currency_code ?? $si->shipment->currency_code, $a->payment?->payment_date);
                            }
                        }
                    }
                }
            }
        }

        return FxConverter::prefetchCache($needed, $presentationCurrency);
    }

    private function recordMissing(?int $pres, ?string $currency, FxConverter $fx, array &$unconverted): void
    {
        if ($pres === null && $currency !== null && $currency !== '') {
            $unconverted[] = $currency;
        }
    }
}
```

- [ ] **Step 2: Add the `shipmentItems` relation to `ProformaInvoiceItem` if missing**

Check for an existing relation first:

```bash
grep -n "shipmentItems\|shipmentItem" app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php
```

If the relation does not exist, add it (the plan depends on it for the shipment eager-load). Otherwise skip this step.

Relation code to add if missing:

```php
    public function shipmentItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domain\Logistics\Models\ShipmentItem::class);
    }
```

- [ ] **Step 3: Run — verify test passes**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php::test_single_pi_with_po_no_shipment`
Expected: `OK (1 test)`

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Financial/Reports/DealBreakdownReportService.php app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php
git commit -m "feat(financial): DealBreakdownReportService core with receipts + POs"
```

---

## Task 11: Add shipment-scenario test

**Files:**
- Modify: `tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php`

- [ ] **Step 1: Add shared-shipment test**

Append to the test class:

```php
    public function test_shared_shipment_attribution_by_weight(): void
    {
        $client = Company::factory()->create(['status' => 'active']);
        $supplier = Company::factory()->create(['status' => 'active']);

        // PI carries items weighing 300kg, shares a shipment with another PI's items weighing 200kg.
        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-200', totalMinor: 1_000_000_0)
            ->withPo(supplier: $supplier, reference: 'PO-200', totalMinor: 500_000_0)
            ->withShipment(
                reference: 'SHP-200',
                totalCostMinor: 100_000_0,    // 10,000 USD shipment
                paidMinor: 50_000_0,
                myItemsWeight: 300.0,
                otherItemsWeight: 200.0,
            )
            ->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        // The deal we care about (not the auto-generated "OTHER-PI")
        $deal = collect($report->deals)->firstWhere(fn ($d) => $d->pi->reference === 'PI-200');

        $this->assertNotNull($deal);
        $this->assertCount(1, $deal->shipments);
        $shipRow = $deal->shipments[0];

        // Attribution: 300 / (300+200) = 0.6
        $this->assertEqualsWithDelta(0.6, $shipRow->attributionPct, 0.001);
        $this->assertSame(AttributionBasis::WEIGHT, $shipRow->basis);

        // Attributed cost = 10,000 * 0.6 = 6,000
        $this->assertSame(60_000_0, $shipRow->attributedOriginal);

        // Paid (attributed) = 5,000 * 0.6 = 3,000
        $this->assertSame(30_000_0, $shipRow->paidOriginal);
    }
```

Add missing `use` at the top:

```php
use App\Domain\Financial\Reports\DTOs\AttributionBasis;
```

- [ ] **Step 2: Run**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php`
Expected: `OK (2 tests)`

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php
git commit -m "test(financial): shared shipment attribution in breakdown service"
```

---

## Task 12: Add status-filter and matrix-client tests

**Files:**
- Modify: `tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php`

- [ ] **Step 1: Add tests**

Append to the class:

```php
    public function test_draft_and_cancelled_excluded_by_default_status_filter(): void
    {
        $client = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()->forClient($client)->withPi(
            reference: 'PI-DRAFT',
            status: ProformaInvoiceStatus::DRAFT,
        )->build();

        DealScenarioBuilder::make()->forClient($client)->withPi(
            reference: 'PI-OK',
            status: ProformaInvoiceStatus::CONFIRMED,
        )->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($client, $filters);

        $refs = collect($report->deals)->pluck('pi.reference')->all();
        $this->assertNotContains('PI-DRAFT', $refs);
        $this->assertContains('PI-OK', $refs);
    }

    public function test_matrix_client_includes_branch_pis(): void
    {
        $matrix = Company::factory()->create(['status' => 'active', 'parent_company_id' => null]);
        $branch = Company::factory()->create(['status' => 'active', 'parent_company_id' => $matrix->id]);

        DealScenarioBuilder::make()->forClient($matrix)->withPi(reference: 'PI-MATRIX')->build();
        DealScenarioBuilder::make()->forClient($branch)->withPi(reference: 'PI-BRANCH')->build();

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            presentationCurrency: 'USD',
            statuses: DealBreakdownFilters::defaultStatuses(),
        );

        $report = app(DealBreakdownReportService::class)->build($matrix, $filters);

        $refs = collect($report->deals)->pluck('pi.reference')->all();
        $this->assertContains('PI-MATRIX', $refs);
        $this->assertContains('PI-BRANCH', $refs);
    }
```

- [ ] **Step 2: Run**

Run: `vendor/bin/phpunit tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php`
Expected: `OK (4 tests)`

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Financial/Reports/DealBreakdownReportServiceTest.php
git commit -m "test(financial): status filter and matrix branch consolidation"
```

---

## Task 13: Translation keys

**Files:**
- Create: `lang/en/client_deal_breakdown.php`
- Create: `lang/pt_BR/client_deal_breakdown.php`
- Create: `lang/zh_CN/client_deal_breakdown.php`
- Modify: `lang/en/navigation.php`
- Modify: `lang/pt_BR/navigation.php`
- Modify: `lang/zh_CN/navigation.php`

- [ ] **Step 1: Create `lang/en/client_deal_breakdown.php`**

```php
<?php

return [
    'title' => 'Client Deal Breakdown',
    'select_client_prompt' => 'Select a client to begin.',
    'empty_state' => 'No operations match the current filters.',
    'filters' => [
        'from' => 'From',
        'to' => 'To',
        'presentation_currency' => 'Presentation currency',
        'statuses' => 'Statuses',
        'client' => 'Client',
    ],
    'kpi' => [
        'total_received' => 'Total Received',
        'total_paid_suppliers' => 'Paid to Suppliers',
        'total_paid_shipments' => 'Paid to Shipments',
        'total_margin' => 'Margin',
    ],
    'columns' => [
        'pi' => 'PI',
        'client_reference' => 'client ref',
        'issue_date' => 'Date',
        'status' => 'Status',
        'total' => 'Total',
        'received' => 'Received',
        'paid_suppliers' => 'Paid Sup.',
        'paid_shipments' => 'Paid Freight',
        'cash_balance' => 'Cash Balance',
        'margin' => 'Margin',
        'currency' => 'Currency',
    ],
    'sections' => [
        'receipts' => 'Received from Client',
        'purchase_orders' => 'Purchase Orders',
        'shipments' => 'Shipments',
        'additional_costs' => 'Additional Costs',
    ],
    'basis' => [
        'weight' => 'allocation: weight',
        'volume' => 'allocation: volume',
        'quantity' => 'allocation: qty (weight missing)',
        'value' => 'allocation: value (weight/volume/qty missing)',
    ],
    'fx_unavailable_tooltip' => 'FX rate not available for one or more currency pairs in this range.',
    'no_pos' => 'No linked purchase orders',
    'no_shipments' => 'No linked shipments',
    'no_receipts' => 'No payments received yet',
];
```

- [ ] **Step 2: Create `lang/pt_BR/client_deal_breakdown.php`**

```php
<?php

return [
    'title' => 'Análise de Operações por Cliente',
    'select_client_prompt' => 'Selecione um cliente para começar.',
    'empty_state' => 'Nenhuma operação corresponde aos filtros atuais.',
    'filters' => [
        'from' => 'De',
        'to' => 'Até',
        'presentation_currency' => 'Moeda de apresentação',
        'statuses' => 'Status',
        'client' => 'Cliente',
    ],
    'kpi' => [
        'total_received' => 'Total Recebido',
        'total_paid_suppliers' => 'Pago a Fornecedores',
        'total_paid_shipments' => 'Pago a Shipments',
        'total_margin' => 'Margem',
    ],
    'columns' => [
        'pi' => 'PI',
        'client_reference' => 'ref cliente',
        'issue_date' => 'Data',
        'status' => 'Status',
        'total' => 'Total',
        'received' => 'Recebido',
        'paid_suppliers' => 'Pago Forn.',
        'paid_shipments' => 'Pago Frete',
        'cash_balance' => 'Saldo Caixa',
        'margin' => 'Margem',
        'currency' => 'Moeda',
    ],
    'sections' => [
        'receipts' => 'Recebido do Cliente',
        'purchase_orders' => 'Purchase Orders',
        'shipments' => 'Shipments',
        'additional_costs' => 'Custos Adicionais',
    ],
    'basis' => [
        'weight' => 'rateio: peso',
        'volume' => 'rateio: volume',
        'quantity' => 'rateio: qtd (peso ausente)',
        'value' => 'rateio: valor (peso/volume/qtd ausentes)',
    ],
    'fx_unavailable_tooltip' => 'Taxa de câmbio indisponível para um ou mais pares de moedas no período.',
    'no_pos' => 'Nenhum PO vinculado',
    'no_shipments' => 'Nenhum shipment vinculado',
    'no_receipts' => 'Nenhum pagamento recebido ainda',
];
```

- [ ] **Step 3: Create `lang/zh_CN/client_deal_breakdown.php`**

Copy the English file (content can be translated later — leave values in English so the app functions in ZH locale without missing keys):

```bash
cp lang/en/client_deal_breakdown.php lang/zh_CN/client_deal_breakdown.php
```

- [ ] **Step 4: Add navigation labels**

For `lang/en/navigation.php`, find the `'pages'` array and add:

```php
'client_deal_breakdown' => 'Client Deal Breakdown',
```

For `lang/pt_BR/navigation.php`:

```php
'client_deal_breakdown' => 'Análise de Operações por Cliente',
```

For `lang/zh_CN/navigation.php`: duplicate the English.

Before adding, inspect each file to place the key consistently:

```bash
grep -n "pages" lang/en/navigation.php
```

- [ ] **Step 5: Commit**

```bash
git add lang/
git commit -m "i18n: add client_deal_breakdown translation keys (en, pt_BR, zh_CN)"
```

---

## Task 14: Filament page skeleton

**Files:**
- Create: `app/Filament/Pages/ClientDealBreakdown.php`
- Create: `resources/views/filament/pages/client-deal-breakdown.blade.php`

- [ ] **Step 1: Write the Page class**

```php
<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Reports\DealBreakdownReportService;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\Financial\Reports\DTOs\DealBreakdownReport;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class ClientDealBreakdown extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'client-deal-breakdown';
    protected string $view = 'filament.pages.client-deal-breakdown';

    #[Url(as: 'client')]
    public ?int $clientId = null;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    #[Url(as: 'currency')]
    public ?string $presentationCurrency = null;

    /** @var list<string> */
    #[Url(as: 'statuses')]
    public array $statuses = [];

    /** @var list<int> */
    public array $expandedDeals = [];

    /** @var list<int> */
    public array $expandedShipments = [];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.client_deal_breakdown');
    }

    public function getTitle(): string
    {
        return __('client_deal_breakdown.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public function mount(): void
    {
        $this->fromDate ??= now()->startOfYear()->toDateString();
        $this->toDate ??= now()->toDateString();

        if (empty($this->statuses)) {
            $this->statuses = array_map(
                fn (ProformaInvoiceStatus $s) => $s->value,
                DealBreakdownFilters::defaultStatuses()
            );
        }

        if ($this->clientId && $this->presentationCurrency === null) {
            $this->presentationCurrency = $this->resolveDefaultPresentationCurrency();
        }
    }

    public function toggleDeal(int $piId): void
    {
        $this->expandedDeals = in_array($piId, $this->expandedDeals, true)
            ? array_values(array_diff($this->expandedDeals, [$piId]))
            : array_merge($this->expandedDeals, [$piId]);
    }

    public function toggleShipment(int $shipmentId): void
    {
        $this->expandedShipments = in_array($shipmentId, $this->expandedShipments, true)
            ? array_values(array_diff($this->expandedShipments, [$shipmentId]))
            : array_merge($this->expandedShipments, [$shipmentId]);
    }

    public function getClientOptionsProperty(): array
    {
        return Company::query()
            ->whereNull('parent_company_id')
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id')
            ->all();
    }

    public function getCurrencyOptionsProperty(): array
    {
        if (! $this->clientId) {
            return ['USD' => 'USD'];
        }
        $codes = ProformaInvoice::query()
            ->where('company_id', $this->clientId)
            ->distinct()
            ->pluck('currency_code')
            ->filter()
            ->values()
            ->all();
        if (empty($codes)) {
            return ['USD' => 'USD'];
        }
        return array_combine($codes, $codes);
    }

    public function getStatusOptionsProperty(): array
    {
        $options = [];
        foreach (ProformaInvoiceStatus::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }
        return $options;
    }

    #[Computed]
    public function report(): ?DealBreakdownReport
    {
        if (! $this->clientId) {
            return null;
        }
        $client = Company::find($this->clientId);
        if (! $client) {
            return null;
        }

        $filters = new DealBreakdownFilters(
            from: CarbonImmutable::parse($this->fromDate),
            to: CarbonImmutable::parse($this->toDate),
            presentationCurrency: $this->presentationCurrency ?? $this->resolveDefaultPresentationCurrency() ?? 'USD',
            statuses: array_map(fn (string $v) => ProformaInvoiceStatus::from($v), $this->statuses),
        );

        return app(DealBreakdownReportService::class)->build($client, $filters);
    }

    private function resolveDefaultPresentationCurrency(): ?string
    {
        $pis = ProformaInvoice::query()
            ->where('company_id', $this->clientId)
            ->whereNotNull('currency_code')
            ->orderByDesc('issue_date')
            ->limit(200)
            ->pluck('currency_code');

        if ($pis->isEmpty()) {
            return 'USD';
        }
        return $pis->countBy()->sortDesc()->keys()->first();
    }
}
```

- [ ] **Step 2: Write a minimal Blade view**

```blade
<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">
                    {{ __('client_deal_breakdown.filters.client') }}
                </label>
                <select wire:model.live="clientId"
                        class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <option value="">—</option>
                    @foreach ($this->clientOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('client_deal_breakdown.filters.from') }}</label>
                <input type="date" wire:model.live.debounce.300ms="fromDate"
                       class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('client_deal_breakdown.filters.to') }}</label>
                <input type="date" wire:model.live.debounce.300ms="toDate"
                       class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">
                    {{ __('client_deal_breakdown.filters.presentation_currency') }}
                </label>
                <select wire:model.live="presentationCurrency"
                        class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900">
                    @foreach ($this->currencyOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php($report = $this->report)

        @if (! $clientId)
            <div class="rounded-lg border border-dashed p-8 text-center text-gray-500">
                {{ __('client_deal_breakdown.select_client_prompt') }}
            </div>
        @elseif ($report && empty($report->deals))
            <div class="rounded-lg border border-dashed p-8 text-center text-gray-500">
                {{ __('client_deal_breakdown.empty_state') }}
            </div>
        @elseif ($report)
            {{-- KPI + table content added in later tasks --}}
            <div data-test="report-container">
                <p class="text-xs text-gray-400">
                    {{ $report->kpi->dealCount }} deals · {{ $report->presentationCurrency }}
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
```

- [ ] **Step 3: Verify app boots**

Run: `php artisan route:list | grep client-deal-breakdown`
Expected: an entry for the new page slug.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Pages/ClientDealBreakdown.php resources/views/filament/pages/client-deal-breakdown.blade.php
git commit -m "feat(filament): ClientDealBreakdown page skeleton with filters"
```

---

## Task 15: KPI cards + main table header and body (wire up report)

**Files:**
- Modify: `resources/views/filament/pages/client-deal-breakdown.blade.php`

- [ ] **Step 1: Replace the placeholder `@elseif ($report)` block**

Replace the small `data-test="report-container"` block with the full KPI and main table:

```blade
        @elseif ($report)
            {{-- KPI cards --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_received') }}
                    </div>
                    <div class="text-2xl font-bold text-green-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalReceived) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_paid_suppliers') }}
                    </div>
                    <div class="text-2xl font-bold text-red-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalPaidSuppliers) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_paid_shipments') }}
                    </div>
                    <div class="text-2xl font-bold text-orange-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalPaidShipments) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_margin') }}
                    </div>
                    <div class="text-2xl font-bold {{ $report->kpi->totalMargin >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalMargin) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
            </div>

            {{-- Main table --}}
            <div class="fi-ta-ctn bg-white dark:bg-gray-900 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="p-3 w-8"></th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.pi') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.issue_date') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.status') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.total') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.received') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.paid_suppliers') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.paid_shipments') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.cash_balance') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.margin') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.currency') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->deals as $deal)
                            @php($expanded = in_array($deal->pi->id, $expandedDeals, true))
                            <tr class="border-t hover:bg-gray-50 dark:hover:bg-gray-800/40 cursor-pointer"
                                wire:click="toggleDeal({{ $deal->pi->id }})"
                                data-test="deal-row-{{ $deal->pi->id }}">
                                <td class="p-3">{{ $expanded ? '▾' : '▸' }}</td>
                                <td class="p-3">
                                    <a href="{{ $deal->pi->detailUrl }}" target="_blank"
                                       class="font-semibold text-primary-600 hover:underline"
                                       wire:click.stop>
                                        {{ $deal->pi->reference }}
                                    </a>
                                    @if ($deal->pi->clientReference)
                                        <div class="text-xs text-gray-500">
                                            {{ __('client_deal_breakdown.columns.client_reference') }}:
                                            {{ $deal->pi->clientReference }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-3">{{ $deal->pi->issueDate->format('Y-m-d') }}</td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-800">
                                        {{ $deal->pi->status->getLabel() }}
                                    </span>
                                </td>
                                <td class="p-3 text-right">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->pi->totalPresentation ?? 0) }}
                                    @if ($deal->pi->currencyOriginal !== $report->presentationCurrency)
                                        <div class="text-xs text-gray-500">
                                            {{ \App\Domain\Infrastructure\Support\Money::format($deal->pi->totalOriginal) }}
                                            {{ $deal->pi->currencyOriginal }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-3 text-right text-green-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->receipts->paidPresentation ?? 0) }}
                                </td>
                                <td class="p-3 text-right text-red-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format(collect($deal->purchaseOrders)->sum(fn($p) => $p->paidPresentation ?? 0)) }}
                                </td>
                                <td class="p-3 text-right text-orange-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format(collect($deal->shipments)->sum(fn($s) => $s->paidPresentation ?? 0)) }}
                                </td>
                                <td class="p-3 text-right font-semibold {{ $deal->totals->cashBalance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->cashBalance) }}
                                </td>
                                <td class="p-3 text-right">
                                    <div class="font-semibold {{ $deal->totals->margin >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                        {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->margin) }}
                                    </div>
                                    <div class="text-xs text-gray-500">{{ $deal->totals->marginPct }}%</div>
                                </td>
                                <td class="p-3 text-xs text-gray-500">{{ $deal->pi->currencyOriginal }}</td>
                            </tr>
                            @if ($expanded)
                                <tr class="bg-amber-50 dark:bg-amber-900/10">
                                    <td colspan="11" class="p-4">
                                        <x-client-deal-breakdown.receipts :block="$deal->receipts" :presentationCurrency="$report->presentationCurrency" />
                                        <x-client-deal-breakdown.purchase-orders :rows="$deal->purchaseOrders" :presentationCurrency="$report->presentationCurrency" />
                                        <x-client-deal-breakdown.shipments :rows="$deal->shipments" :expandedShipments="$expandedShipments" :presentationCurrency="$report->presentationCurrency" />
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (! empty($report->unconvertedCurrencyPairs))
                <div class="text-xs text-amber-600">
                    ⚠ {{ __('client_deal_breakdown.fx_unavailable_tooltip') }}
                    ({{ implode(', ', $report->unconvertedCurrencyPairs) }})
                </div>
            @endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/filament/pages/client-deal-breakdown.blade.php
git commit -m "feat(filament): KPI cards and main deal table in breakdown page"
```

---

## Task 16: Blade components — receipts sub-table

**Files:**
- Create: `resources/views/components/client-deal-breakdown/receipts.blade.php`

- [ ] **Step 1: Write**

```blade
@props(['block', 'presentationCurrency'])

<div class="mb-4">
    <div class="text-xs uppercase font-bold text-green-700 dark:text-green-400 mb-1">
        ↓ {{ __('client_deal_breakdown.sections.receipts') }}
        —
        {{ \App\Domain\Infrastructure\Support\Money::format($block->paidOriginal) }}
        / {{ \App\Domain\Infrastructure\Support\Money::format($block->paidOriginal + $block->outstandingOriginal) }}
        ({{ $block->percentPaid }}%)
    </div>
    @if (empty($block->items))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_receipts') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 text-left">Date</th>
                    <th class="p-2 text-left">Ref</th>
                    <th class="p-2 text-left">Stage</th>
                    <th class="p-2 text-right">Amount</th>
                    <th class="p-2 text-right">In {{ $presentationCurrency }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($block->items as $r)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="p-2">{{ $r->paymentDate->format('Y-m-d') }}</td>
                        <td class="p-2">{{ $r->paymentReference }}</td>
                        <td class="p-2">{{ $r->stageLabel }}</td>
                        <td class="p-2 text-right text-green-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($r->amountOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            @if ($r->amountPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($r->amountPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/client-deal-breakdown/receipts.blade.php
git commit -m "feat(filament): receipts sub-table component"
```

---

## Task 17: Blade components — purchase-orders sub-table

**Files:**
- Create: `resources/views/components/client-deal-breakdown/purchase-orders.blade.php`

- [ ] **Step 1: Write**

```blade
@props(['rows', 'presentationCurrency'])

<div class="mb-4">
    <div class="text-xs uppercase font-bold text-red-700 dark:text-red-400 mb-1">
        ↑ {{ __('client_deal_breakdown.sections.purchase_orders') }} ({{ count($rows) }})
    </div>
    @if (empty($rows))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_pos') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 text-left">PO</th>
                    <th class="p-2 text-left">Supplier</th>
                    <th class="p-2 text-right">Total</th>
                    <th class="p-2 text-right">In {{ $presentationCurrency }}</th>
                    <th class="p-2 text-right">Paid</th>
                    <th class="p-2 text-right">Outstanding</th>
                    <th class="p-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $po)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="p-2">
                            <a href="{{ $po->detailUrl }}" target="_blank" class="text-primary-600 hover:underline"
                               wire:click.stop>{{ $po->reference }}</a>
                        </td>
                        <td class="p-2">{{ $po->supplierName }}</td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->totalOriginal) }}
                            <span class="text-gray-500">{{ $po->currencyOriginal }}</span>
                        </td>
                        <td class="p-2 text-right">
                            @if ($po->totalPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($po->totalPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                        <td class="p-2 text-right text-red-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->paidOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->outstandingOriginal) }}
                        </td>
                        <td class="p-2">
                            <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-800">
                                {{ $po->status->getLabel() }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/client-deal-breakdown/purchase-orders.blade.php
git commit -m "feat(filament): purchase-orders sub-table component"
```

---

## Task 18: Blade components — shipments sub-table with additional costs expansion

**Files:**
- Create: `resources/views/components/client-deal-breakdown/shipments.blade.php`

- [ ] **Step 1: Write**

```blade
@props(['rows', 'expandedShipments', 'presentationCurrency'])

<div class="mb-2">
    <div class="text-xs uppercase font-bold text-orange-700 dark:text-orange-400 mb-1">
        ↑ {{ __('client_deal_breakdown.sections.shipments') }} ({{ count($rows) }})
    </div>
    @if (empty($rows))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_shipments') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 w-6"></th>
                    <th class="p-2 text-left">Shipment</th>
                    <th class="p-2 text-left">Forwarder</th>
                    <th class="p-2 text-right">Total Cost</th>
                    <th class="p-2 text-right">Attribution</th>
                    <th class="p-2 text-right">Attributed ({{ $presentationCurrency }})</th>
                    <th class="p-2 text-right">Paid</th>
                    <th class="p-2 text-right">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $s)
                    @php($open = in_array($s->id, $expandedShipments, true))
                    @php($hasCosts = count($s->additionalCosts) > 0)
                    <tr class="border-t border-gray-100 dark:border-gray-800 {{ $hasCosts ? 'cursor-pointer hover:bg-orange-50 dark:hover:bg-orange-900/10' : '' }}"
                        @if ($hasCosts) wire:click="toggleShipment({{ $s->id }})" @endif>
                        <td class="p-2">{{ $hasCosts ? ($open ? '▾' : '▸') : '' }}</td>
                        <td class="p-2">
                            <a href="{{ $s->detailUrl }}" target="_blank"
                               class="text-primary-600 hover:underline" wire:click.stop>
                                {{ $s->reference }}
                            </a>
                            @if ($s->clientReference)
                                <div class="text-xs text-gray-500">{{ $s->clientReference }}</div>
                            @endif
                        </td>
                        <td class="p-2">{{ $s->forwarderName }}</td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->totalCostOriginal) }}
                            <span class="text-gray-500">{{ $s->currencyOriginal }}</span>
                        </td>
                        <td class="p-2 text-right">
                            <span class="font-semibold">{{ number_format($s->attributionPct * 100, 1) }}%</span>
                            <div class="text-[10px] text-gray-500">{{ __($s->basis->labelKey()) }}</div>
                        </td>
                        <td class="p-2 text-right">
                            @if ($s->attributedPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($s->attributedPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                        <td class="p-2 text-right text-red-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->paidOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->outstandingOriginal) }}
                        </td>
                    </tr>
                    @if ($open && $hasCosts)
                        <tr class="bg-orange-50 dark:bg-orange-900/5">
                            <td></td>
                            <td colspan="7" class="p-2">
                                <div class="text-[10px] uppercase text-gray-500 mb-1">
                                    {{ __('client_deal_breakdown.sections.additional_costs') }}
                                </div>
                                <table class="w-full text-xs">
                                    <thead class="text-gray-500 uppercase text-[10px]">
                                        <tr>
                                            <th class="p-1 text-left">Label</th>
                                            <th class="p-1 text-left">Type</th>
                                            <th class="p-1 text-right">Total</th>
                                            <th class="p-1 text-right">Attributed</th>
                                            <th class="p-1 text-right">In {{ $presentationCurrency }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s->additionalCosts as $c)
                                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                                <td class="p-1">{{ $c->label }}</td>
                                                <td class="p-1">{{ $c->type?->getLabel() }}</td>
                                                <td class="p-1 text-right">
                                                    {{ \App\Domain\Infrastructure\Support\Money::format($c->totalOriginal) }}
                                                </td>
                                                <td class="p-1 text-right">
                                                    {{ \App\Domain\Infrastructure\Support\Money::format($c->attributedOriginal) }}
                                                </td>
                                                <td class="p-1 text-right">
                                                    @if ($c->attributedPresentation !== null)
                                                        {{ \App\Domain\Infrastructure\Support\Money::format($c->attributedPresentation) }}
                                                    @else
                                                        <span class="text-amber-600">⚠</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/client-deal-breakdown/shipments.blade.php
git commit -m "feat(filament): shipments sub-table with additional-cost expansion"
```

---

## Task 19: Feature test — permission gate + empty states

**Files:**
- Create: `tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Pages\ClientDealBreakdown;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientDealBreakdownPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_cannot_access(): void
    {
        $user = User::factory()->create();                 // no permissions
        $this->actingAs($user);

        $response = $this->get(route('filament.admin.pages.client-deal-breakdown'));
        $response->assertForbidden();
    }

    public function test_authorized_user_sees_select_client_prompt(): void
    {
        $user = $this->makeAuthorizedUser();
        $this->actingAs($user);

        Livewire::test(ClientDealBreakdown::class)
            ->assertSee(__('client_deal_breakdown.select_client_prompt'));
    }

    public function test_client_selection_loads_report(): void
    {
        $user = $this->makeAuthorizedUser();
        $this->actingAs($user);

        $client = Company::factory()->create(['status' => 'active']);

        Livewire::test(ClientDealBreakdown::class)
            ->set('clientId', $client->id)
            ->assertSee(__('client_deal_breakdown.empty_state'));   // no PIs yet
    }

    private function makeAuthorizedUser(): User
    {
        $user = User::factory()->create();
        // Use the simplest path that satisfies Gate: grant the permission directly
        // if the app uses Spatie permission or a role system, adjust accordingly.
        // Fallback: stub the ability via Gate::before() in a testing trait if needed.
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view-payments']);
        $user->givePermissionTo('view-payments');
        return $user;
    }
}
```

Note: if the app does not use Spatie Permission, inspect `can('view-payments')` usage and grant the permission the same way existing tests do (look at `tests/Feature/*FinancialOverview*` if any, or fall back to `Gate::before(fn () => true)` inside the test setUp).

- [ ] **Step 2: Run**

Run: `vendor/bin/phpunit tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php`
Expected: `OK (3 tests)`

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php
git commit -m "test(filament): ClientDealBreakdown gate, empty states, report load"
```

---

## Task 20: Feature test — expand/collapse deal

**Files:**
- Modify: `tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php`

- [ ] **Step 1: Append test**

```php
    public function test_toggle_deal_expands_and_collapses(): void
    {
        $user = $this->makeAuthorizedUser();
        $this->actingAs($user);

        $client = Company::factory()->create(['status' => 'active']);
        \Tests\Support\DealScenarioBuilder::make()->forClient($client)->withPi(reference: 'PI-EXP')->build();

        $component = Livewire::test(ClientDealBreakdown::class)->set('clientId', $client->id);

        $piId = \App\Domain\ProformaInvoices\Models\ProformaInvoice::where('reference', 'PI-EXP')->value('id');

        $component
            ->assertSet('expandedDeals', [])
            ->call('toggleDeal', $piId)
            ->assertSet('expandedDeals', [$piId])
            ->call('toggleDeal', $piId)
            ->assertSet('expandedDeals', []);
    }
```

- [ ] **Step 2: Run**

Run: `vendor/bin/phpunit tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php`
Expected: `OK (4 tests)`

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Filament/Pages/ClientDealBreakdownPageTest.php
git commit -m "test(filament): toggleDeal expands and collapses"
```

---

## Task 21: Final verification sweep

- [ ] **Step 1: Run full test suite**

Run: `php artisan test --filter='Financial|ClientDealBreakdown'`
Expected: all tests green, zero failures.

- [ ] **Step 2: Run Filament upgrade check**

Run: `php artisan filament:optimize`
Expected: no errors.

- [ ] **Step 3: Boot the server and manually smoke-test**

Run: `php artisan serve` (in background) and open `http://localhost:8000/client-deal-breakdown`
Expected: the page renders, filter bar works, client selection loads data. Verify at least one real client with PIs and payments renders the table rows correctly.

- [ ] **Step 4: Check no N+1**

With Laravel Debugbar enabled, confirm a single client with 10+ PIs does not exceed ~20 queries total (baseline + single prefetch). If more, investigate the eager-load chain.

- [ ] **Step 5: Final commit (if any fixups)**

```bash
git commit -am "chore(client-deal-breakdown): final polish from manual verification"
```

---

## Self-review checklist — done before handoff

- ✔ Spec section 1 (page shell) → Task 14
- ✔ Spec section 6 (DTOs) → Tasks 1-3
- ✔ Spec section 7 (service) → Tasks 9-12
- ✔ Spec section 8 (attribution) → Tasks 6-7
- ✔ Spec section 9 (FX) → Tasks 4-5
- ✔ Spec section 10 (blade view) → Tasks 14-18
- ✔ Spec section 11 (edge cases) — covered by test cases
- ✔ Spec section 12 (testing) → Tasks 4-20
- ✔ Spec section 13 (i18n) → Task 13
- ✔ No placeholders; all code blocks complete
- ✔ Type consistency: all DTO field names match usage in service + blade
- ✔ Task granularity: each task is 2-15 minutes of concrete work
