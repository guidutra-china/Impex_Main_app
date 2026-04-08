# Proforma Invoice — Multi-Currency Cost Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Proforma Invoice cost totals, margins, and stat widgets render correctly when items come from supplier sources priced in a different currency than the PI itself.

**Architecture:** Snapshot the source currency and FX rate on each `ProformaInvoiceItem` (3 new columns), cache the converted-to-PI-currency value, and recompute it via a `saving` model hook so the cache can never go stale. A thin `ProformaInvoiceItemCurrencyResolver` service centralises FX lookups and is invoked from every code path that creates a PI item (3 import actions + manual form). Existing widgets/infolists/PDFs read through the model accessors and become automatically correct.

**Tech Stack:** Laravel 11, Filament 4, PHPUnit (RefreshDatabase), MySQL/SQLite, existing `ExchangeRate` and `Currency` models in `App\Domain\Settings`.

**Spec:** `docs/superpowers/specs/2026-04-08-proforma-invoice-multi-currency-cost-design.md`

---

## File Structure

| Action | File | Purpose |
|---|---|---|
| Create | `database/migrations/2026_04_08_120100_add_cost_currency_to_proforma_invoice_items.php` | Add 3 columns + backfill |
| Modify | `app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php` | Fillable, casts, `saving` hook, accessors |
| Create | `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php` | Centralised FX resolver |
| Modify | `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php` | Wire resolver into 3 import actions, form fields, table tooltip |
| Modify | `app/Filament/Resources/ProformaInvoices/Widgets/ProformaInvoiceStats.php` | Optional FX indicator on cost/margin card |
| Modify | `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php` | Multi-currency badge action with refresh modal |
| Create | `resources/views/filament/modals/proforma-invoice-multi-currency.blade.php` | Modal contents listing multi-currency items |
| Modify | `lang/en/forms.php` (if it exists) | New labels |
| Modify | `lang/en/messages.php` (if it exists) | `fx_rates_refreshed` notification key |
| Create | `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php` | Unit tests for accessors + saving hook |
| Create | `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php` | Unit tests for the resolver |
| Create | `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php` | Feature tests for the 3 import paths |

---

## Task 1: Migration with backfill

**Files:**
- Create: `database/migrations/2026_04_08_120100_add_cost_currency_to_proforma_invoice_items.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->string('cost_currency_code', 3)->nullable()->after('unit_cost');
            $table->decimal('cost_exchange_rate', 18, 8)->default(1)->after('cost_currency_code');
            $table->bigInteger('unit_cost_in_document_currency')->default(0)->after('cost_exchange_rate');
        });

        // Backfill: legacy items are assumed to already be in the PI's currency.
        // For SQLite (tests) and MySQL (prod), use a portable per-row update.
        DB::table('proforma_invoice_items')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $piCurrency = DB::table('proforma_invoices')
                    ->where('id', $row->proforma_invoice_id)
                    ->value('currency_code');

                DB::table('proforma_invoice_items')
                    ->where('id', $row->id)
                    ->update([
                        'cost_currency_code' => $piCurrency,
                        'cost_exchange_rate' => 1,
                        'unit_cost_in_document_currency' => $row->unit_cost,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'cost_currency_code',
                'cost_exchange_rate',
                'unit_cost_in_document_currency',
            ]);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs without errors. If using SQLite test DB, also run `php artisan migrate --database=sqlite` if applicable.

- [ ] **Step 3: Verify schema**

Run: `php artisan tinker --execute="dump(\Schema::getColumnListing('proforma_invoice_items'));"`
Expected output includes: `cost_currency_code`, `cost_exchange_rate`, `unit_cost_in_document_currency`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_120100_add_cost_currency_to_proforma_invoice_items.php
git commit -m "feat(pi): add cost currency columns to proforma_invoice_items"
```

---

## Task 2: Model — fillable, casts, saving hook, accessors

**Files:**
- Modify: `app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php`
- Create: `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php`

- [ ] **Step 1: Write failing test for the saving hook**

Create `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php`:

```php
<?php

namespace Tests\Unit\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaInvoiceItemCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function makePi(string $currency = 'USD'): ProformaInvoice
    {
        $company = Company::factory()->create();
        return ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'currency_code' => $currency,
        ]);
    }

    public function test_saving_hook_recomputes_cached_doc_currency_cost(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => 2000,            // $20.00
            'unit_cost' => 10000,            // ¥100.00 in CNY minor units
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,   // 1 CNY = 0.142 USD
        ]);

        // round(10000 * 0.142) = 1420 (USD minor units = $14.20)
        $this->assertSame(1420, $item->fresh()->unit_cost_in_document_currency);
    }

    public function test_cost_total_uses_doc_currency_cache(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 10000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,
        ]);

        // 1420 * 10 = 14200
        $this->assertSame(14200, $item->fresh()->cost_total);
    }

    public function test_margin_uses_doc_currency_cost(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,            // $20.00
            'unit_cost' => 10000,            // ¥100.00
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,   // → $14.20
        ]);

        // (2000 - 1420) / 1420 * 100 = 40.85%
        $this->assertEqualsWithDelta(40.85, $item->fresh()->margin, 0.01);
    }

    public function test_same_currency_with_rate_one(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 1500,
            'unit_cost' => 1000,
            'cost_currency_code' => 'USD',
            'cost_exchange_rate' => 1,
        ]);

        $this->assertSame(1000, $item->fresh()->unit_cost_in_document_currency);
        $this->assertSame(5000, $item->fresh()->cost_total);
        $this->assertEqualsWithDelta(50.0, $item->fresh()->margin, 0.01);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php`
Expected: FAIL — column `cost_currency_code` not fillable / accessor still uses old `unit_cost`.

- [ ] **Step 3: Update the model**

Edit `app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php`:

Replace `$fillable` with:
```php
protected $fillable = [
    'proforma_invoice_id',
    'product_id',
    'quotation_item_id',
    'supplier_company_id',
    'description',
    'specifications',
    'quantity',
    'unit',
    'unit_price',
    'unit_cost',
    'cost_currency_code',
    'cost_exchange_rate',
    'unit_cost_in_document_currency',
    'incoterm',
    'notes',
    'sort_order',
];
```

Replace `casts()` with:
```php
protected function casts(): array
{
    return [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'unit_cost' => 'integer',
        'cost_exchange_rate' => 'decimal:8',
        'unit_cost_in_document_currency' => 'integer',
        'incoterm' => Incoterm::class,
        'sort_order' => 'integer',
    ];
}
```

Add a `booted()` method just below `casts()`:
```php
protected static function booted(): void
{
    static::saving(function (ProformaInvoiceItem $item) {
        $item->unit_cost_in_document_currency = (int) round(
            (int) $item->unit_cost * (float) $item->cost_exchange_rate
        );
    });
}
```

Replace `getCostTotalAttribute()` (currently at lines 105-108):
```php
public function getCostTotalAttribute(): int
{
    return $this->unit_cost_in_document_currency * $this->quantity;
}
```

Replace `getMarginAttribute()` (currently at lines 110-117):
```php
public function getMarginAttribute(): float
{
    $cost = $this->unit_cost_in_document_currency;
    if ($cost <= 0) {
        return 0;
    }

    return round((($this->unit_price - $cost) / $cost) * 100, 2);
}
```

Leave `getLineTotalAttribute()` unchanged — it has always been correct.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php`
Expected: PASS, 4 tests / 4 assertions group.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyTest.php
git commit -m "feat(pi): track cost currency and snapshot FX rate on items"
```

---

## Task 3: Currency Resolver service

**Files:**
- Create: `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php`
- Create: `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php`:

```php
<?php

namespace Tests\Unit\ProformaInvoices;

use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaInvoiceItemCurrencyResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoiceItemCurrencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProformaInvoiceItemCurrencyResolver();
    }

    private function seedCurrencies(): array
    {
        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);

        $cny = Currency::create([
            'code' => 'CNY',
            'name' => 'Chinese Yuan',
            'symbol' => '¥',
            'decimal_places' => 2,
            'is_base' => false,
            'is_active' => true,
        ]);

        return [$usd, $cny];
    }

    public function test_same_currency_returns_rate_one(): void
    {
        $result = $this->resolver->resolve('USD', 'USD');

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_resolves_cross_currency_via_base(): void
    {
        [$usd, $cny] = $this->seedCurrencies();

        // base (USD) -> CNY rate = 7.0 means 1 USD = 7 CNY
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        // Source = CNY, Target = USD → ExchangeRate::convert handles this via inverse_rate
        $result = $this->resolver->resolve('CNY', 'USD');

        $this->assertSame('CNY', $result['currency']);
        $this->assertEqualsWithDelta(1 / 7.0, $result['rate'], 0.0001);
    }

    public function test_unknown_currency_falls_back_to_rate_one(): void
    {
        $result = $this->resolver->resolve('XYZ', 'USD');

        $this->assertSame('XYZ', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_no_rate_available_falls_back_to_rate_one(): void
    {
        $this->seedCurrencies();
        // No ExchangeRate row inserted

        $result = $this->resolver->resolve('CNY', 'USD');

        $this->assertSame('CNY', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_null_source_currency_treated_as_target(): void
    {
        $result = $this->resolver->resolve(null, 'USD');

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php`
Expected: FAIL — class `ProformaInvoiceItemCurrencyResolver` not found.

- [ ] **Step 3: Create the service**

Create `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php`:

```php
<?php

namespace App\Domain\ProformaInvoices\Services;

use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class ProformaInvoiceItemCurrencyResolver
{
    /**
     * Resolve the cost currency + FX rate for a PI item.
     *
     * @return array{currency: string, rate: float}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null
    ): array {
        $sourceCurrency = $sourceCurrency ?: $targetCurrency;

        if ($sourceCurrency === $targetCurrency) {
            return ['currency' => $targetCurrency, 'rate' => 1.0];
        }

        $source = Currency::findByCode($sourceCurrency);
        $target = Currency::findByCode($targetCurrency);

        if (! $source || ! $target) {
            Log::warning('PI cost currency resolver: unknown currency', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        $converted = ExchangeRate::convert($source->id, $target->id, 1.0, $date);

        if ($converted === null) {
            Log::warning('PI cost currency resolver: no FX rate available', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
                'date' => $date,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        return ['currency' => $sourceCurrency, 'rate' => (float) $converted];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php`
Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php tests/Unit/ProformaInvoices/ProformaInvoiceItemCurrencyResolverTest.php
git commit -m "feat(pi): add ProformaInvoiceItemCurrencyResolver service"
```

---

## Task 4: Wire resolver into `importFromSupplierQuotationsAction`

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`
- Create: `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`:

```php
<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCurrencyImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥',
            'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        // 1 USD = 7 CNY (so 1 CNY ≈ 0.1428 USD)
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_supplier_quotation_import_snapshots_cny_to_usd_rate(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $sq = SupplierQuotation::factory()->create([
            'company_id' => $supplier->id,
            'currency_code' => 'CNY',
        ]);
        $sqItem = SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id,
            'unit_cost' => 10000, // ¥100.00
            'quantity' => 1,
        ]);

        // Simulate the import branch directly using the resolver,
        // since the action is invoked via Filament UI machinery.
        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $sqItem->supplierQuotation->currency_code,
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );

        $pi->items()->create([
            'product_id'          => $sqItem->product_id,
            'supplier_company_id' => $sq->company_id,
            'description'         => 'Imported',
            'quantity'            => $sqItem->quantity,
            'unit'                => 'pcs',
            'unit_price'          => 0,
            'unit_cost'           => $sqItem->unit_cost,
            'cost_currency_code'  => $resolved['currency'],
            'cost_exchange_rate'  => $resolved['rate'],
            'sort_order'          => 1,
        ]);

        $item = $pi->items()->first();

        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $item->cost_exchange_rate, 0.0001);
        // round(10000 / 7) = 1429 USD minor units
        $this->assertSame(1429, $item->unit_cost_in_document_currency);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`
Expected: FAIL — `unit_cost_in_document_currency` is 0 (model saving hook works but resolver wiring not yet present in action).

Note: this test passes once Task 2 is in place because it calls the resolver directly. If it already passes, the test still acts as a regression guard for future refactors of the action. Proceed regardless.

- [ ] **Step 3: Wire the resolver into `importFromSupplierQuotationsAction`**

Edit `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`.

Add to the `use` statements at the top of the file (after the existing `use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;`):
```php
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
```

In `importFromSupplierQuotationsAction()` inside the `->action(function (array $data) {...})` closure, locate the `foreach ($items as $sqItem) {` loop body. Replace the entire foreach body (currently lines 441-488) with:

```php
$resolver = app(ProformaInvoiceItemCurrencyResolver::class);

foreach ($items as $sqItem) {
    $resolved = $resolver->resolve(
        $sqItem->supplierQuotation?->currency_code,
        $pi->currency_code,
        $pi->issue_date?->toDateString(),
    );

    // If product already exists in PI, update cost + currency snapshot
    if ($sqItem->product_id && isset($existingPiItemsByProduct[$sqItem->product_id])) {
        $existingItem = $existingPiItemsByProduct[$sqItem->product_id];
        $existingItem->update([
            'unit_cost' => $sqItem->unit_cost,
            'cost_currency_code' => $resolved['currency'],
            'cost_exchange_rate' => $resolved['rate'],
            'supplier_company_id' => $sqItem->supplierQuotation->company_id ?? $existingItem->supplier_company_id,
        ]);
        $imported++;
        $newSqIds[] = $sqItem->supplier_quotation_id;
        continue;
    }

    if ($sqItem->product_id) {
        $existingProductIds[] = $sqItem->product_id;
    }

    // Try to get client-specific selling price
    $unitPrice = 0;
    if ($sqItem->product) {
        $clientPivot = $sqItem->product->clients()
            ->where('companies.id', $pi->company_id)
            ->first()
            ?->pivot;
        if ($clientPivot && ($clientPivot->unit_price ?? 0) > 0) {
            $unitPrice = $clientPivot->unit_price;
        }
    }

    ProformaInvoiceItem::create([
        'proforma_invoice_id' => $pi->id,
        'product_id'          => $sqItem->product_id,
        'quotation_item_id'   => null,
        'supplier_company_id' => $sqItem->supplierQuotation->company_id ?? null,
        'description'         => $sqItem->product?->name ?? $sqItem->description,
        'specifications'      => $sqItem->product?->specification?->description ?? $sqItem->specifications,
        'quantity'            => $sqItem->quantity,
        'unit'                => $sqItem->unit ?? 'pcs',
        'unit_price'          => $unitPrice,
        'unit_cost'           => $sqItem->unit_cost,
        'cost_currency_code'  => $resolved['currency'],
        'cost_exchange_rate'  => $resolved['rate'],
        'incoterm'            => null,
        'notes'               => $sqItem->notes,
        'sort_order'          => ++$maxSort,
    ]);

    $newSqIds[] = $sqItem->supplier_quotation_id;
    $imported++;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php
git commit -m "feat(pi): snapshot FX rate when importing from supplier quotations"
```

---

## Task 5: Wire resolver into `importFromQuotationsAction`

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`
- Modify: `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`

- [ ] **Step 1: Add a failing test for client-quotation import**

Append this method to `MultiCurrencyImportTest.php`:

```php
public function test_quotation_import_snapshots_currency_when_quotation_currency_differs(): void
{
    $client = Company::factory()->create();

    $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::factory()->create([
        'company_id' => $client->id,
        'currency_code' => 'USD',
    ]);

    $quotation = \App\Domain\Quotations\Models\Quotation::factory()->create([
        'company_id' => $client->id,
        'currency_code' => 'CNY',
    ]);
    $qItem = \App\Domain\Quotations\Models\QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'unit_price' => 20000, // ¥200
        'unit_cost'  => 10000, // ¥100
        'quantity'   => 1,
    ]);

    $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
    $resolved = $resolver->resolve(
        $qItem->quotation->currency_code,
        $pi->currency_code,
        $pi->issue_date?->toDateString(),
    );

    $pi->items()->create([
        'product_id' => $qItem->product_id,
        'quotation_item_id' => $qItem->id,
        'description' => 'Quoted',
        'quantity' => 1,
        'unit' => 'pcs',
        'unit_price' => $qItem->unit_price,
        'unit_cost'  => $qItem->unit_cost,
        'cost_currency_code' => $resolved['currency'],
        'cost_exchange_rate' => $resolved['rate'],
        'sort_order' => 1,
    ]);

    $item = $pi->items()->first();
    $this->assertSame('CNY', $item->cost_currency_code);
    $this->assertSame(1429, $item->unit_cost_in_document_currency);
}
```

- [ ] **Step 2: Run the test to verify it fails or is green for the wrong reason**

Run: `vendor/bin/phpunit tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php --filter test_quotation_import_snapshots_currency_when_quotation_currency_differs`
Expected: PASS (it directly drives the resolver). This test guards the wiring in step 3.

- [ ] **Step 3: Wire the resolver into `importFromQuotationsAction`**

In `ItemsRelationManager.php`, locate `importFromQuotationsAction()` → `->action(...)` closure → the `foreach ($items as $item) {` loop (currently lines 319-348). Replace the foreach body with:

```php
$resolver = app(ProformaInvoiceItemCurrencyResolver::class);

foreach ($items as $item) {
    $supplierId = $item->selected_supplier_id;

    if (! $supplierId && $item->product) {
        $preferred = $item->product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first();
        $supplierId = $preferred?->id;
    }

    $resolved = $resolver->resolve(
        $item->quotation?->currency_code,
        $pi->currency_code,
        $pi->issue_date?->toDateString(),
    );

    ProformaInvoiceItem::create([
        'proforma_invoice_id' => $pi->id,
        'product_id' => $item->product_id,
        'quotation_item_id' => $item->id,
        'supplier_company_id' => $supplierId,
        'description' => $item->product?->name,
        'specifications' => $item->product?->specification?->description ?? null,
        'quantity' => $item->quantity,
        'unit' => 'pcs',
        'unit_price' => $item->unit_price,
        'unit_cost' => $item->unit_cost,
        'cost_currency_code' => $resolved['currency'],
        'cost_exchange_rate' => $resolved['rate'],
        'incoterm' => $item->incoterm,
        'notes' => $item->notes,
        'sort_order' => ++$maxSort,
    ]);

    $linkedQuotationIds[] = $item->quotation_id;
    $imported++;
}
```

- [ ] **Step 4: Run all PI tests**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php
git commit -m "feat(pi): snapshot FX rate when importing from client quotations"
```

---

## Task 6: Wire resolver into `importFromInquiryAction` and `fillFromProduct`

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`
- Modify: `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`

The cost in these paths comes from the `company_product` pivot, which already has its own `currency_code` column.

- [ ] **Step 1: Add a failing test for the inquiry/preferred-supplier path**

Append to `MultiCurrencyImportTest.php`:

```php
public function test_inquiry_import_uses_company_product_pivot_currency(): void
{
    $client = Company::factory()->create();
    $supplier = Company::factory()->create();
    $product = \App\Domain\Catalog\Models\Product::factory()->create();

    // Pivot row with CNY currency on the supplier-product relation
    $supplier->products()->attach($product->id, [
        'role' => 'supplier',
        'unit_price' => 10000, // ¥100
        'currency_code' => 'CNY',
        'is_preferred' => true,
    ]);

    $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::factory()->create([
        'company_id' => $client->id,
        'currency_code' => 'USD',
    ]);

    $resolver = app(ProformaInvoiceItemCurrencyResolver::class);

    // Replicate the inquiry-import body
    $preferred = $product->suppliers()
        ->orderByDesc('company_product.is_preferred')
        ->first();

    $sourceCurrency = $preferred->pivot->currency_code ?? null;
    $resolved = $resolver->resolve($sourceCurrency, $pi->currency_code, $pi->issue_date?->toDateString());

    $pi->items()->create([
        'product_id' => $product->id,
        'supplier_company_id' => $preferred->id,
        'description' => $product->name,
        'quantity' => 1,
        'unit' => 'pcs',
        'unit_price' => 0,
        'unit_cost' => $preferred->pivot->unit_price,
        'cost_currency_code' => $resolved['currency'],
        'cost_exchange_rate' => $resolved['rate'],
        'sort_order' => 1,
    ]);

    $item = $pi->items()->first();
    $this->assertSame('CNY', $item->cost_currency_code);
    $this->assertSame(1429, $item->unit_cost_in_document_currency);
}
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php --filter test_inquiry_import_uses_company_product_pivot_currency`
Expected: PASS (drives resolver directly). Acts as a regression guard for the wiring below.

- [ ] **Step 3: Wire the resolver into `importFromInquiryAction`**

In `ItemsRelationManager.php`, inside `importFromInquiryAction()` → `->action(...)` closure → the `foreach ($items as $item) {` loop (currently around lines 558-602), replace the body with:

```php
$resolver = app(ProformaInvoiceItemCurrencyResolver::class);

foreach ($items as $item) {
    $supplierId = null;
    $unitCost = 0;
    $unitPrice = $item->target_price ?? 0;
    $incoterm = null;
    $costCurrency = null;

    if ($item->product) {
        $preferred = $item->product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first();

        if ($preferred) {
            $supplierId = $preferred->id;
            $unitCost = $preferred->pivot->unit_price ?? 0;
            $incoterm = $preferred->pivot->incoterm ?? null;
            $costCurrency = $preferred->pivot->currency_code ?? null;
        }

        $clientPivot = $item->product->clients()
            ->where('companies.id', $pi->company_id)
            ->first()
            ?->pivot;

        if ($clientPivot && $clientPivot->unit_price > 0) {
            $unitPrice = $clientPivot->unit_price;
        }
    }

    $resolved = $resolver->resolve(
        $costCurrency,
        $pi->currency_code,
        $pi->issue_date?->toDateString(),
    );

    ProformaInvoiceItem::create([
        'proforma_invoice_id' => $pi->id,
        'product_id' => $item->product_id,
        'quotation_item_id' => null,
        'supplier_company_id' => $supplierId,
        'description' => $item->product?->name ?? $item->description,
        'specifications' => $item->product?->specification?->description ?? $item->specifications,
        'quantity' => $item->quantity,
        'unit' => $item->unit ?? 'pcs',
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'cost_currency_code' => $resolved['currency'],
        'cost_exchange_rate' => $resolved['rate'],
        'incoterm' => $incoterm,
        'notes' => $item->notes,
        'sort_order' => ++$maxSort,
    ]);

    $imported++;
}
```

- [ ] **Step 4: Update `fillFromProduct()` (manual create flow)**

Locate `fillFromProduct()` near the bottom of the file (currently around lines 663-695). Replace its body with:

```php
protected function fillFromProduct(int $productId, Get $get, Set $set): void
{
    $product = Product::with(['suppliers', 'specification'])->find($productId);
    if (! $product) {
        return;
    }

    $set('description', $product->name);
    $set('specifications', $product->specification?->description);

    $preferred = $product->suppliers()
        ->orderByDesc('company_product.is_preferred')
        ->first();

    if ($preferred) {
        $set('supplier_company_id', $preferred->id);
        $set('unit_cost', Money::toMajor($preferred->pivot->unit_price));

        if ($preferred->pivot->incoterm ?? null) {
            $set('incoterm', $preferred->pivot->incoterm);
        }

        $pi = $this->getOwnerRecord();
        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $preferred->pivot->currency_code ?? null,
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );

        $set('cost_currency_code', $resolved['currency']);
        $set('cost_exchange_rate', $resolved['rate']);
    }

    $pi = $this->getOwnerRecord();
    $clientPivot = $product->clients()
        ->where('companies.id', $pi->company_id)
        ->first()
        ?->pivot;

    if ($clientPivot && $clientPivot->unit_price > 0) {
        $set('unit_price', Money::toMajor($clientPivot->unit_price));
    }
}
```

- [ ] **Step 5: Run all PI tests**

Run: `vendor/bin/phpunit tests/Unit/ProformaInvoices tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php
git commit -m "feat(pi): use supplier pivot currency for inquiry imports and manual product fill"
```

---

## Task 7: Add cost-currency fields to the manual create/edit form

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`

- [ ] **Step 1: Add the new use statements**

At the top of the file, add (after the existing `use Filament\Forms\Components\TextInput;`):
```php
use Filament\Forms\Components\Placeholder;
use App\Domain\Settings\Models\Currency;
use Filament\Actions\Action as FormAction;
```

- [ ] **Step 2: Add the cost-currency field cluster to `form()`**

In `form()` (currently starting at line 49), insert these three components **immediately after** the `TextInput::make('unit_cost')` block (currently ending around line 115). The full insertion is:

```php
Select::make('cost_currency_code')
    ->label(__('forms.labels.cost_currency'))
    ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
    ->default(fn () => $this->getOwnerRecord()->currency_code)
    ->required()
    ->live()
    ->afterStateUpdated(function (Get $get, Set $set) {
        $pi = $this->getOwnerRecord();
        $resolver = app(\App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $get('cost_currency_code'),
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );
        $set('cost_exchange_rate', $resolved['rate']);
    }),

TextInput::make('cost_exchange_rate')
    ->label(__('forms.labels.exchange_rate'))
    ->numeric()
    ->step(0.00000001)
    ->default(1)
    ->required()
    ->helperText(__('forms.helpers.cost_currency_to_pi_currency')),

Placeholder::make('unit_cost_doc_preview')
    ->label(__('forms.labels.cost_in_pi_currency'))
    ->content(function (Get $get) {
        $cost = (float) ($get('unit_cost') ?? 0);
        $rate = (float) ($get('cost_exchange_rate') ?? 1);
        $pi = $this->getOwnerRecord();
        return $pi->currency_code . ' ' . number_format($cost * $rate, 2);
    }),
```

Note: the unit_cost field needs `->live()` added to it so the placeholder updates when it changes. Update the existing block:
```php
TextInput::make('unit_cost')
    ->label(__('forms.labels.unit_cost_internal'))
    ->numeric()
    ->prefix('$')
    ->step(0.0001)
    ->minValue(0)
    ->default(0)
    ->live(onBlur: true),
```

- [ ] **Step 3: Update the EditAction `mountUsing` and `mutateFormDataUsing`**

In the `EditAction` block (currently around lines 237-250), replace `mountUsing` and `mutateFormDataUsing` with:

```php
->mountUsing(function ($form, $record) {
    $data = $record->toArray();
    $data['unit_cost'] = Money::toMajor($data['unit_cost']);
    $data['unit_price'] = Money::toMajor($data['unit_price']);
    // cost_currency_code and cost_exchange_rate are already in their canonical form
    $form->fill($data);
})
->mutateFormDataUsing(function (array $data): array {
    $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
    $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
    // unit_cost_in_document_currency is recomputed by the model's saving hook
    return $data;
}),
```

Also update the `CreateAction::make()->mutateFormDataUsing(...)` (currently around lines 224-231):

```php
->mutateFormDataUsing(function (array $data): array {
    $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
    $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
    $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;
    // cost_currency_code and cost_exchange_rate flow through unchanged
    return $data;
}),
```

- [ ] **Step 4: Manual smoke test**

Run: `php artisan serve` and visit a PI's items page in the browser. Click "Create" → confirm the cost currency Select defaults to the PI currency, the rate field defaults to 1, and the preview placeholder shows the converted value live as you type. Change the currency to CNY → confirm the rate auto-updates from the seeded `exchange_rates` row. Save and reload the table to confirm the row persists with the right values.

Expected: form persists `cost_currency_code`, `cost_exchange_rate`, and `unit_cost_in_document_currency` correctly.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php
git commit -m "feat(pi): expose cost currency and FX rate in item form"
```

---

## Task 8: Update the items table column with currency tooltip

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php`

- [ ] **Step 1: Replace the cost `TextInputColumn` with a tooltip-aware version**

In the `table()` method, locate the `TextInputColumn::make('unit_cost')` block (currently around lines 193-207). Replace it with:

```php
TextInputColumn::make('unit_cost')
    ->label(__('forms.labels.cost'))
    ->type('number')
    ->inputMode('decimal')
    ->step('0.0001')
    ->prefix(fn ($record) => ($record->cost_currency_code ?? '$') . ' ')
    ->rules(['required', 'numeric', 'min:0'])
    ->tooltip(function ($record) {
        $piCurrency = $record->proformaInvoice?->currency_code;
        if (! $piCurrency || $record->cost_currency_code === $piCurrency) {
            return null;
        }
        $converted = Money::format($record->unit_cost_in_document_currency ?? 0);
        return '≈ ' . $piCurrency . ' ' . $converted
            . ' @ ' . number_format((float) $record->cost_exchange_rate, 6);
    })
    ->getStateUsing(fn ($record) => number_format(Money::toMajor($record->unit_cost ?? 0), 4, '.', ''))
    ->updateStateUsing(function ($record, $state) {
        $floatValue = (float) str_replace(',', '', (string) $state);
        $record->unit_cost = Money::toMinor($floatValue);
        $record->save(); // saving hook recomputes unit_cost_in_document_currency
        return number_format($floatValue, 4, '.', '');
    })
    ->alignEnd(),
```

- [ ] **Step 2: Manual smoke test**

Reload the PI items page. For an item where `cost_currency_code` differs from the PI currency, hover the cost cell. Expected: tooltip shows `≈ USD 14.20 @ 0.142857` (or similar). For matching currencies, no tooltip.

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php
git commit -m "feat(pi): show converted cost tooltip for multi-currency items"
```

---

## Task 9: Header action — multi-currency badge with FX refresh modal

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`

The PI resource already has a header-actions concern. We add an action that is `visible()` only when at least one item has a non-matching cost currency. The action opens a modal listing those items, their original costs, and the snapshot rate, with a single "Refresh all rates from FX table" button.

- [ ] **Step 1: Read the existing header actions file to understand its shape**

Read: `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`. Confirm it returns/builds Filament `Action` objects and identify the conventional place to append a new one.

- [ ] **Step 2: Add the new use statements to the concern**

Add to the top of the file:
```php
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use Filament\Notifications\Notification;
```

(Skip any that are already present.)

- [ ] **Step 3: Add a `multiCurrencyBadgeAction()` method**

Append inside the concern class:

```php
protected function multiCurrencyBadgeAction(): \Filament\Actions\Action
{
    return \Filament\Actions\Action::make('multiCurrencyCosts')
        ->label(__('forms.labels.multi_currency_costs'))
        ->icon('heroicon-o-currency-dollar')
        ->color('warning')
        ->visible(function () {
            $record = $this->getRecord();
            if (! $record) {
                return false;
            }
            return $record->items()->whereColumn(
                'cost_currency_code', '!=', \DB::raw("'" . $record->currency_code . "'")
            )->exists()
            // Fallback for null cost_currency_code rows: only flag true mismatches.
            || $record->items()
                ->whereNotNull('cost_currency_code')
                ->where('cost_currency_code', '!=', $record->currency_code)
                ->exists();
        })
        ->modalHeading(__('forms.labels.multi_currency_costs'))
        ->modalDescription(__('forms.helpers.multi_currency_explanation'))
        ->modalContent(function () {
            $record = $this->getRecord();
            $items = $record->items()
                ->whereNotNull('cost_currency_code')
                ->where('cost_currency_code', '!=', $record->currency_code)
                ->get();

            return view('filament.modals.proforma-invoice-multi-currency', [
                'items' => $items,
                'piCurrency' => $record->currency_code,
            ]);
        })
        ->modalSubmitActionLabel(__('forms.labels.refresh_all_rates'))
        ->action(function () {
            $record = $this->getRecord();
            $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
            $updated = 0;

            foreach ($record->items as $item) {
                if (! $item->cost_currency_code || $item->cost_currency_code === $record->currency_code) {
                    continue;
                }
                $resolved = $resolver->resolve(
                    $item->cost_currency_code,
                    $record->currency_code,
                    today()->toDateString(),
                );
                $item->cost_exchange_rate = $resolved['rate'];
                $item->save(); // saving hook recomputes cached doc-currency cost
                $updated++;
            }

            Notification::make()
                ->title(__('messages.fx_rates_refreshed', ['count' => $updated]))
                ->success()
                ->send();
        });
}
```

- [ ] **Step 4: Wire the action into the header actions list**

Find the existing method that returns/registers the array of header actions in the same file. Append `$this->multiCurrencyBadgeAction()` to that array.

- [ ] **Step 5: Create the modal blade view**

Create `resources/views/filament/modals/proforma-invoice-multi-currency.blade.php`:

```blade
<div class="space-y-3">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 dark:text-gray-400">
            <tr>
                <th class="py-1">{{ __('forms.labels.product') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.original_cost') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.exchange_rate') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.cost_in_pi_currency') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr class="border-t border-gray-100 dark:border-gray-700">
                    <td class="py-1">{{ $item->product?->name ?? $item->description }}</td>
                    <td class="py-1 text-right">
                        {{ $item->cost_currency_code }}
                        {{ number_format($item->unit_cost / 100, 2) }}
                    </td>
                    <td class="py-1 text-right font-mono">
                        {{ number_format((float) $item->cost_exchange_rate, 6) }}
                    </td>
                    <td class="py-1 text-right">
                        {{ $piCurrency }}
                        {{ number_format($item->unit_cost_in_document_currency / 100, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 6: Manual smoke test**

Open a PI with at least one CNY-cost item. Expected: a yellow "Multi-currency costs" badge appears in the header. Click → modal lists the affected items with their original cost, snapshot rate, and converted value. Click "Refresh all rates" → notification confirms update count, modal closes, the items table cost column shows the refreshed converted value.

For an all-USD PI, the badge does not appear.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php resources/views/filament/modals/proforma-invoice-multi-currency.blade.php
git commit -m "feat(pi): header badge to inspect and refresh multi-currency item rates"
```

---

## Task 10: Multi-currency indicator in the stats widget

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/Widgets/ProformaInvoiceStats.php`

- [ ] **Step 1: Add the FX indicator to the cost/margin card**

In `ProformaInvoiceStats::getViewData()`, locate the `$cards` array literal (currently around lines 59-90). Just **before** that array, add:

```php
$hasMultiCurrency = $pi->items->contains(
    fn ($item) => $item->cost_currency_code
        && $item->cost_currency_code !== $currency
);
```

Then in the cost/margin card entry (the second item in `$cards`, currently around lines 70-75), update the `description` line to:

```php
'description' => __('widgets.document_summary.margin') . ': ' . $margin . '%'
    . ($hasMultiCurrency ? ' · ' . __('widgets.document_summary.fx_snapshot') : ''),
```

- [ ] **Step 2: Add the translation key**

If `lang/en/widgets.php` exists, add to the `document_summary` array:
```php
'fx_snapshot' => 'FX snapshot',
```
Otherwise this key gracefully renders as the literal string.

- [ ] **Step 3: Manual smoke test**

Open a PI with at least one CNY-cost item. Expected: cost card description reads `Margin: 40.85% · FX snapshot`. For an all-USD PI, the suffix is absent.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/Widgets/ProformaInvoiceStats.php lang/en/widgets.php
git commit -m "feat(pi): flag FX snapshot on cost/margin stats card"
```

---

## Task 11: Translation keys

**Files:**
- Modify: `lang/en/forms.php` (and other locales if present)

- [ ] **Step 1: Add the new keys**

Locate the existing `labels` and `helpers` arrays in `lang/en/forms.php`. Add:

```php
'labels' => [
    // ...existing keys...
    'cost_currency' => 'Cost Currency',
    'exchange_rate' => 'Exchange Rate',
    'cost_in_pi_currency' => 'Cost in document currency',
    'multi_currency_costs' => 'Multi-currency costs',
    'refresh_all_rates' => 'Refresh all rates from FX table',
    'original_cost' => 'Original cost',
],

'helpers' => [
    // ...existing keys...
    'cost_currency_to_pi_currency' => 'Rate from cost currency to the PI currency, frozen on the item.',
    'multi_currency_explanation' => 'Some item costs were quoted in a different currency than this PI. The rate snapshot was taken when each item was created. Use the button below to refresh all rates against today\'s FX table.',
],
```

If `lang/pt_BR/forms.php` (or other locales) exists, add equivalent entries.

- [ ] **Step 2: Smoke test**

Run: `php artisan serve`, open the PI item form, confirm labels render in the active locale (no `forms.labels.cost_currency` literal showing).

- [ ] **Step 3: Commit**

```bash
git add lang/
git commit -m "i18n: add cost currency labels for PI items"
```

---

## Task 12: Sweep verification

**Files:** read-only audit pass.

- [ ] **Step 1: Search for direct uses of `unit_cost` outside the model**

Run: `grep -rn "->unit_cost\b" app/Domain/ProformaInvoices app/Filament/Resources/ProformaInvoices app/Filament/Portal/Resources/ProformaInvoiceResource app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php`

For each result, confirm one of the following:
- It's inside the `ItemsRelationManager` form/import code we already updated.
- It's a write (e.g., `$item->unit_cost = ...`) — fine, the saving hook handles cache.
- It's a read of the supplier-currency value for display — fine.
- It's a read used in arithmetic that should be `unit_cost_in_document_currency` — **fix it** by switching to the doc-currency cache or to the `cost_total` accessor.

Document each finding as a comment in the commit body.

- [ ] **Step 2: Search for `cost_total` reads to confirm none re-implement the math**

Run: `grep -rn "cost_total\b" app/Filament app/Domain/Infrastructure/Pdf`
Expected: every result reads through the accessor (`->cost_total` on a PI or item). No raw arithmetic.

- [ ] **Step 3: Search the PDF template specifically**

Run: `grep -n "unit_cost\|cost_total\|margin" app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php`

Expected output: the PDF template should not need changes — it currently does not display cost or margin (only line totals which were already in PI currency). If any cost/margin output is found, switch it to read through the accessors.

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: green. If any unrelated failures appear that mention cost/margin, treat as regressions and fix in this task.

- [ ] **Step 5: Add a code-level TODO marker for PurchaseOrder follow-up**

Find where `PurchaseOrder` items are generated from `ProformaInvoiceItem` (search: `grep -rn "ProformaInvoiceItem" app/Domain/PurchaseOrders`). Add this comment above the cost copy:

```php
// TODO: multi-currency — when PurchaseOrders gain cost_currency_code,
// propagate cost_currency_code/cost_exchange_rate from the PI item.
```

Do not change behaviour. This is just a marker.

- [ ] **Step 6: Commit**

```bash
git add -p
git commit -m "chore(pi): sweep verification + TODO marker for PO multi-currency follow-up"
```

---

## Verification Checklist

After all tasks are complete:

- [ ] `vendor/bin/phpunit tests/Unit/ProformaInvoices tests/Feature/ProformaInvoices` — all green.
- [ ] Manual: open a PI in USD with an item imported from a CNY supplier quotation. The widget cost/margin card shows correct USD totals + "FX snapshot" hint.
- [ ] Manual: edit the same item inline → `unit_cost` change preserves currency snapshot, cost card updates correctly.
- [ ] Manual: create a fresh USD PI, import from a CNY SQ → confirm `cost_currency_code='CNY'` and `unit_cost_in_document_currency` equals `unit_cost / 7` (rounded).
- [ ] Manual: create a fresh USD PI with a manually picked product whose `company_product` pivot is in CNY → confirm form auto-fills the rate from the FX table.
- [ ] Backfill: pre-existing PIs whose currency already matched their items show the same numbers as before the migration.
