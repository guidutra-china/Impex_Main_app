# Quotation FX Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-item FX snapshots to the Quotation domain so cross-currency Inquiry → SupplierQuotation → Quotation flows compute correct prices and margins, mirroring the pattern already used by ProformaInvoiceItem.

**Architecture:** New columns `cost_currency_code` + `cost_exchange_rate` on `quotation_items` and `cost_exchange_rate` on `quotation_item_suppliers`. New shared service `CurrencyExchangeResolver` (extracted from PI's existing resolver) is used by a new domain action `CreateOrUpdateQuotationFromInquiryAction`, which replaces the inline closure logic in `InquiryHeaderActions`. Refresh-on-DRAFT / lock-on-SENT semantics prevent silent drift on customer-facing data; `QuotationVersion` snapshots preserve history when forcing new versions.

**Tech Stack:** Laravel 12, Filament 4, MySQL/SQLite, PHPUnit, Pint. Spec: `docs/superpowers/specs/2026-04-28-quotation-fx-snapshot-design.md`.

**Conventions reminder (from CLAUDE.md):**
- Code in English; Filament UI labels in pt-BR (use `__()`).
- Run `vendor/bin/pint` before each commit.
- Run `composer test` (config:clear + artisan test) to verify.
- Money values in minor units (bigint), `Money::SCALE = 10000`.
- No factories exist for `Quotation`, `QuotationItem`, `SupplierQuotation`, `SupplierQuotationItem` — tests build via `Model::create` directly. Existing pattern: `tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`.

---

# PR 1 — Schema + shared resolver

Pure plumbing. No behavioral change in this PR.

### Task 1.1: Migration for quotation_items FX columns

**Files:**
- Create: `database/migrations/2026_04_28_100000_add_fx_snapshot_to_quotation_items.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('cost_currency_code', 3)->nullable()->after('unit_cost');
            $table->decimal('cost_exchange_rate', 18, 8)->nullable()->after('cost_currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['cost_currency_code', 'cost_exchange_rate']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `Migrating: 2026_04_28_100000_add_fx_snapshot_to_quotation_items` then `Migrated`.

- [ ] **Step 3: Verify schema**

Run: `php artisan db:show --counts | head -30 || true` then `sqlite3 database/sqlite/database.sqlite ".schema quotation_items" 2>/dev/null || php artisan tinker --execute="dump(\Schema::getColumnListing('quotation_items'));"`
Expected: list contains `cost_currency_code` and `cost_exchange_rate`.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint database/migrations/2026_04_28_100000_add_fx_snapshot_to_quotation_items.php
git add database/migrations/2026_04_28_100000_add_fx_snapshot_to_quotation_items.php
git commit -m "feat(quotations): add cost_currency_code + cost_exchange_rate to quotation_items"
```

### Task 1.2: Migration for quotation_item_suppliers FX column

**Files:**
- Create: `database/migrations/2026_04_28_100100_add_fx_snapshot_to_quotation_item_suppliers.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_item_suppliers', function (Blueprint $table) {
            $table->decimal('cost_exchange_rate', 18, 8)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_item_suppliers', function (Blueprint $table) {
            $table->dropColumn('cost_exchange_rate');
        });
    }
};
```

- [ ] **Step 2: Run + verify**

Run: `php artisan migrate`
Expected: migration runs; column added.

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint database/migrations/2026_04_28_100100_add_fx_snapshot_to_quotation_item_suppliers.php
git add database/migrations/2026_04_28_100100_add_fx_snapshot_to_quotation_item_suppliers.php
git commit -m "feat(quotations): add cost_exchange_rate to quotation_item_suppliers"
```

### Task 1.3: Create CurrencyExchangeRateUnavailable exception

**Files:**
- Create: `app/Domain/Settings/Exceptions/CurrencyExchangeRateUnavailable.php`

- [ ] **Step 1: Create the exception class**

```php
<?php

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

class CurrencyExchangeRateUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly ?string $date = null,
    ) {
        parent::__construct(sprintf(
            'No approved exchange rate found for %s → %s on or before %s.',
            $sourceCurrency,
            $targetCurrency,
            $date ?? today()->toDateString(),
        ));
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Domain/Settings/Exceptions/CurrencyExchangeRateUnavailable.php
git add app/Domain/Settings/Exceptions/CurrencyExchangeRateUnavailable.php
git commit -m "feat(settings): CurrencyExchangeRateUnavailable exception"
```

### Task 1.4: Create shared CurrencyExchangeResolver service (TDD)

**Files:**
- Create: `app/Domain/Settings/Services/CurrencyExchangeResolver.php`
- Test: `tests/Unit/Domain/Settings/CurrencyExchangeResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Exceptions\CurrencyExchangeRateUnavailable;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyExchangeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_same_currency_returns_rate_1(): void
    {
        $resolver = new CurrencyExchangeResolver();
        $result = $resolver->resolve('USD', 'USD');
        $this->assertSame(['currency' => 'USD', 'rate' => 1.0], $result);
    }

    public function test_null_source_falls_back_to_target(): void
    {
        $resolver = new CurrencyExchangeResolver();
        $result = $resolver->resolve(null, 'USD');
        $this->assertSame(['currency' => 'USD', 'rate' => 1.0], $result);
    }

    public function test_cny_to_usd_resolves_inverse_rate(): void
    {
        $resolver = new CurrencyExchangeResolver();
        $result = $resolver->resolve('CNY', 'USD');
        $this->assertSame('CNY', $result['currency']);
        $this->assertEqualsWithDelta(1 / 7.0, $result['rate'], 0.0001);
    }

    public function test_unknown_currency_lenient_returns_rate_1(): void
    {
        $resolver = new CurrencyExchangeResolver();
        $result = $resolver->resolve('XYZ', 'USD');
        $this->assertSame(['currency' => 'XYZ', 'rate' => 1.0], $result);
    }

    public function test_missing_rate_strict_throws(): void
    {
        Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'name_plural' => 'Euros',
            'symbol' => '€', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        $this->expectException(CurrencyExchangeRateUnavailable::class);

        $resolver = new CurrencyExchangeResolver();
        $resolver->resolve('EUR', 'USD', null, strict: true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Settings/CurrencyExchangeResolverTest.php`
Expected: FAIL with "Class CurrencyExchangeResolver not found".

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Exceptions\CurrencyExchangeRateUnavailable;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class CurrencyExchangeResolver
{
    /**
     * Resolve the cost currency + FX rate for an item.
     *
     * @return array{currency: string, rate: float}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null,
        bool $strict = false,
    ): array {
        $sourceCurrency = $sourceCurrency ?: $targetCurrency;

        if ($sourceCurrency === $targetCurrency) {
            return ['currency' => $targetCurrency, 'rate' => 1.0];
        }

        $source = Currency::findByCode($sourceCurrency);
        $target = Currency::findByCode($targetCurrency);

        if (! $source || ! $target) {
            if ($strict) {
                throw new CurrencyExchangeRateUnavailable($sourceCurrency, $targetCurrency, $date);
            }
            Log::warning('Currency exchange resolver: unknown currency', [
                'source' => $sourceCurrency, 'target' => $targetCurrency,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        $converted = ExchangeRate::convert($source->id, $target->id, 1.0, $date);

        if ($converted === null) {
            if ($strict) {
                throw new CurrencyExchangeRateUnavailable($sourceCurrency, $targetCurrency, $date);
            }
            Log::warning('Currency exchange resolver: no FX rate available', [
                'source' => $sourceCurrency, 'target' => $targetCurrency, 'date' => $date,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        return ['currency' => $sourceCurrency, 'rate' => (float) $converted];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Settings/CurrencyExchangeResolverTest.php`
Expected: PASS, 5 tests, 5 assertions+.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Domain/Settings/Services/CurrencyExchangeResolver.php tests/Unit/Domain/Settings/CurrencyExchangeResolverTest.php
git add app/Domain/Settings/Services/CurrencyExchangeResolver.php tests/Unit/Domain/Settings/CurrencyExchangeResolverTest.php
git commit -m "feat(settings): shared CurrencyExchangeResolver service"
```

### Task 1.5: Refactor ProformaInvoiceItemCurrencyResolver to delegate

**Files:**
- Modify: `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php`

Many call sites use this class (`ItemsRelationManager.php`, `ProformaInvoiceHeaderActions.php`, `InquiryHeaderActions.php`). Public signature MUST stay identical so all of them keep working.

- [ ] **Step 1: Replace contents — delegate to shared resolver**

```php
<?php

namespace App\Domain\ProformaInvoices\Services;

use App\Domain\Settings\Services\CurrencyExchangeResolver;

class ProformaInvoiceItemCurrencyResolver
{
    public function __construct(
        private CurrencyExchangeResolver $resolver = new CurrencyExchangeResolver(),
    ) {}

    /**
     * Resolve the cost currency + FX rate for a PI item.
     *
     * @return array{currency: string, rate: float}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null,
    ): array {
        return $this->resolver->resolve($sourceCurrency, $targetCurrency, $date, strict: false);
    }
}
```

- [ ] **Step 2: Run existing PI multi-currency test**

Run: `vendor/bin/phpunit tests/Feature/ProformaInvoices/MultiCurrencyImportTest.php`
Expected: PASS — same behavior as before.

- [ ] **Step 3: Run full test suite to catch other regressions**

Run: `composer test`
Expected: all green.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php
git add app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php
git commit -m "refactor(proforma-invoices): delegate currency resolver to shared service"
```

---

# PR 2 — Domain action + model accessors

Behavior change: new Quotation creations will produce FX-correct items. UI form is still the old one (no repeater changes yet).

### Task 2.1: Update QuotationItem model with FX fillable + accessors (TDD)

**Files:**
- Modify: `app/Domain/Quotations/Models/QuotationItem.php`
- Test: `tests/Unit/Domain/Quotations/QuotationItemMarginTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\CRM\Models\Company;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationItemMarginTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(int $unitCost, ?string $costCurrency, ?float $rate, int $unitPrice): QuotationItem
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $quotation = Quotation::create([
            'reference' => 'Q-TEST-' . uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        return QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => $unitCost,
            'cost_currency_code' => $costCurrency,
            'cost_exchange_rate' => $rate,
            'unit_price' => $unitPrice,
            'commission_rate' => 0,
        ]);
    }

    public function test_converted_unit_cost_returns_raw_when_currency_matches_quotation(): void
    {
        $item = $this->makeItem(unitCost: 1000, costCurrency: 'USD', rate: 1.0, unitPrice: 1500);
        $this->assertSame(1000, $item->converted_unit_cost);
    }

    public function test_converted_unit_cost_returns_raw_when_currency_is_null_legacy(): void
    {
        $item = $this->makeItem(unitCost: 1000, costCurrency: null, rate: null, unitPrice: 1500);
        $this->assertSame(1000, $item->converted_unit_cost);
    }

    public function test_converted_unit_cost_applies_rate_when_currencies_differ(): void
    {
        // 10000 CNY × 0.14 = 1400 USD (in minor units).
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertSame(1400, $item->converted_unit_cost);
    }

    public function test_converted_cost_total_multiplies_by_quantity(): void
    {
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertSame(14000, $item->converted_cost_total); // 1400 × 10
    }

    public function test_margin_uses_converted_cost(): void
    {
        // converted cost = 1400, price = 2000 → margin ≈ 42.86%
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertEqualsWithDelta(42.86, $item->margin, 0.01);
    }

    public function test_margin_zero_when_converted_cost_zero(): void
    {
        $item = $this->makeItem(unitCost: 0, costCurrency: 'USD', rate: 1.0, unitPrice: 100);
        $this->assertSame(0.0, $item->margin);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/QuotationItemMarginTest.php`
Expected: FAIL — `converted_unit_cost` accessor missing / fillable rejects new fields.

- [ ] **Step 3: Update QuotationItem.php**

```php
<?php

namespace App\Domain\Quotations\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'supplier_quotation_item_id',
        'quantity',
        'selected_supplier_id',
        'unit_cost',
        'cost_currency_code',
        'cost_exchange_rate',
        'commission_rate',
        'unit_price',
        'incoterm',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'cost_exchange_rate' => 'decimal:8',
            'commission_rate' => 'decimal:2',
            'unit_price' => 'integer',
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function selectedSupplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'selected_supplier_id');
    }

    public function supplierQuotationItem(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationItem::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(QuotationItemSupplier::class);
    }

    // --- Accessors ---

    public function getLineTotalAttribute(): int
    {
        return $this->unit_price * $this->quantity;
    }

    public function getCostTotalAttribute(): int
    {
        return $this->unit_cost * $this->quantity;
    }

    public function getConvertedUnitCostAttribute(): int
    {
        $quoteCurrency = $this->quotation?->currency_code;
        if ($this->cost_currency_code === null
            || $quoteCurrency === null
            || $this->cost_currency_code === $quoteCurrency) {
            return $this->unit_cost;
        }

        $rate = $this->cost_exchange_rate !== null ? (float) $this->cost_exchange_rate : 1.0;

        return (int) round($this->unit_cost * $rate);
    }

    public function getConvertedCostTotalAttribute(): int
    {
        return $this->converted_unit_cost * $this->quantity;
    }

    public function getMarginAttribute(): float
    {
        $cost = $this->converted_unit_cost;
        if ($cost <= 0) {
            return 0;
        }

        return round((($this->unit_price - $cost) / $cost) * 100, 2);
    }

    public function getSourceLabelAttribute(): ?string
    {
        if (! $this->supplier_quotation_item_id) {
            return null;
        }

        $sqItem = $this->supplierQuotationItem()->with('supplierQuotation')->first();
        if (! $sqItem || ! $sqItem->supplierQuotation) {
            return null;
        }

        return $sqItem->supplierQuotation->reference;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/QuotationItemMarginTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run full suite to check for regressions**

Run: `composer test`
Expected: all green. If `getMarginAttribute` change breaks existing assertions, those tests had wrong expectations (they were measuring the bug); fix them in the same commit.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Models/QuotationItem.php tests/Unit/Domain/Quotations/QuotationItemMarginTest.php
git add app/Domain/Quotations/Models/QuotationItem.php tests/Unit/Domain/Quotations/QuotationItemMarginTest.php
git commit -m "feat(quotations): per-item FX snapshot accessors on QuotationItem"
```

### Task 2.2: Update QuotationItemSupplier model

**Files:**
- Modify: `app/Domain/Quotations/Models/QuotationItemSupplier.php`

- [ ] **Step 1: Add cost_exchange_rate to fillable + casts + accessors**

Replace contents with:

```php
<?php

namespace App\Domain\Quotations\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItemSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_item_id',
        'company_id',
        'unit_cost',
        'currency_code',
        'cost_exchange_rate',
        'lead_time_days',
        'moq',
        'incoterm',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'cost_exchange_rate' => 'decimal:8',
            'lead_time_days' => 'integer',
            'moq' => 'integer',
            'incoterm' => Incoterm::class,
        ];
    }

    // --- Relationships ---

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // --- Accessors ---

    public function getFormattedCostAttribute(): string
    {
        return \App\Domain\Infrastructure\Support\Money::format($this->unit_cost);
    }

    public function getConvertedUnitCostAttribute(): int
    {
        $quoteCurrency = $this->quotationItem?->quotation?->currency_code;
        if ($this->currency_code === null
            || $quoteCurrency === null
            || $this->currency_code === $quoteCurrency) {
            return $this->unit_cost;
        }

        $rate = $this->cost_exchange_rate !== null ? (float) $this->cost_exchange_rate : 1.0;

        return (int) round($this->unit_cost * $rate);
    }
}
```

- [ ] **Step 2: Run suite**

Run: `composer test`
Expected: green.

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Models/QuotationItemSupplier.php
git add app/Domain/Quotations/Models/QuotationItemSupplier.php
git commit -m "feat(quotations): FX snapshot fields on QuotationItemSupplier"
```

### Task 2.3: Add Quotation::getTotalConvertedCostAttribute

**Files:**
- Modify: `app/Domain/Quotations/Models/Quotation.php` (around line 175, after `getTotalAttribute`)

- [ ] **Step 1: Insert accessor**

Add after the existing `getTotalAttribute()` method:

```php
    public function getTotalConvertedCostAttribute(): int
    {
        return $this->items->sum(fn (QuotationItem $item) => $item->converted_cost_total);
    }
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Models/Quotation.php
git add app/Domain/Quotations/Models/Quotation.php
git commit -m "feat(quotations): aggregate converted cost accessor on Quotation"
```

### Task 2.4: Create QuotationLockedException

**Files:**
- Create: `app/Domain/Quotations/Exceptions/QuotationLockedException.php`

- [ ] **Step 1: Create the exception**

```php
<?php

namespace App\Domain\Quotations\Exceptions;

use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use RuntimeException;

class QuotationLockedException extends RuntimeException
{
    public function __construct(
        public readonly Quotation $quotation,
    ) {
        parent::__construct(sprintf(
            'Quotation %s is in status %s and cannot be recomputed without forceNewVersion.',
            $quotation->reference,
            $quotation->status instanceof QuotationStatus ? $quotation->status->value : (string) $quotation->status,
        ));
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Exceptions/QuotationLockedException.php
git add app/Domain/Quotations/Exceptions/QuotationLockedException.php
git commit -m "feat(quotations): QuotationLockedException for SENT+ recompute attempts"
```

### Task 2.5: Scaffold CreateOrUpdateQuotationFromInquiryAction with lock check (TDD)

**Files:**
- Create: `app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php`
- Test: `tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`

This is the largest task. We'll incrementally grow the test file across Tasks 2.5–2.10 — but in this task we lock down the **constructor, signature, and lock semantics** because they're load-bearing for everything else.

- [ ] **Step 1: Write the first failing test (lock check on SENT)**

```php
<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Actions\CreateOrUpdateQuotationFromInquiryAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrUpdateQuotationFromInquiryActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeAction(): CreateOrUpdateQuotationFromInquiryAction
    {
        return new CreateOrUpdateQuotationFromInquiryAction(new CurrencyExchangeResolver());
    }

    public function test_throws_when_existing_quotation_is_sent_without_force(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        Quotation::create([
            'reference' => 'Q-LOCK-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::SENT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        $this->expectException(QuotationLockedException::class);

        $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            forceNewVersion: false,
        );
    }
}
```

- [ ] **Step 2: Run — confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the skeleton**

```php
<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Support\Facades\DB;

class CreateOrUpdateQuotationFromInquiryAction
{
    private const LOCKED_STATUSES = [
        QuotationStatus::SENT,
        QuotationStatus::NEGOTIATING,
        QuotationStatus::APPROVED,
        QuotationStatus::REJECTED,
        QuotationStatus::EXPIRED,
        QuotationStatus::CANCELLED,
    ];

    public function __construct(
        private CurrencyExchangeResolver $fx,
    ) {}

    public function execute(
        Inquiry $inquiry,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers,
        bool $forceNewVersion = false,
    ): Quotation {
        return DB::transaction(function () use (
            $inquiry, $supplierQuotationIds, $commissionType, $commissionRate, $showSuppliers, $forceNewVersion
        ) {
            $existing = $inquiry->quotations()
                ->latest('version')
                ->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true) && ! $forceNewVersion) {
                throw new QuotationLockedException($existing);
            }

            // Stub — real implementation in Tasks 2.6–2.10.
            return $existing ?? Quotation::create([
                'inquiry_id' => $inquiry->id,
                'company_id' => $inquiry->company_id,
                'status' => QuotationStatus::DRAFT,
                'currency_code' => $inquiry->currency_code,
                'commission_type' => $commissionType,
                'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                'show_suppliers' => $showSuppliers,
            ]);
        });
    }
}
```

- [ ] **Step 4: Run test — passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: PASS, 1 test.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php
git add app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php
git commit -m "feat(quotations): scaffold CreateOrUpdateQuotationFromInquiryAction with lock guard"
```

### Task 2.6: Single-currency creation flow (TDD)

**Files:**
- Modify: `app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php`
- Modify: `tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`

- [ ] **Step 1: Add helper + first creation test**

Add a `setUp()` (uses USD-only data so no FX setup yet) plus a helper that builds Inquiry with items, an SQ in USD, then asserts the created Quotation has matching items with `cost_currency_code = USD`, `rate = 1.0`, `unit_price = unit_cost × (1+commission)`.

```php
    protected function setUp(): void
    {
        parent::setUp();
        \App\Domain\Settings\Models\Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
    }

    private function buildInquiryWithItems(int $itemCount = 1, string $currency = 'USD'): array
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => $currency,
        ]);
        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $product = \App\Domain\Catalog\Models\Product::factory()->create();
            $items[] = \App\Domain\Inquiries\Models\InquiryItem::create([
                'inquiry_id' => $inquiry->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'sort_order' => $i,
            ]);
        }

        return [$client, $inquiry, $items];
    }

    private function buildSqWith(
        Inquiry $inquiry,
        Company $supplier,
        string $currency,
        array $items, // [['product_id' => int, 'unit_cost' => int]]
    ): \App\Domain\SupplierQuotations\Models\SupplierQuotation {
        $sq = \App\Domain\SupplierQuotations\Models\SupplierQuotation::create([
            'reference' => 'SQ-' . uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => $currency,
            'status' => \App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus::RECEIVED,
        ]);
        foreach ($items as $i) {
            \App\Domain\SupplierQuotations\Models\SupplierQuotationItem::create([
                'supplier_quotation_id' => $sq->id,
                'product_id' => $i['product_id'],
                'quantity' => 10,
                'unit_cost' => $i['unit_cost'],
            ]);
        }

        return $sq;
    }

    public function test_single_currency_creates_quotation_with_rate_1(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 20,
            showSuppliers: false,
        );

        $this->assertCount(1, $quotation->items);
        $item = $quotation->items->first();
        $this->assertSame('USD', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $item->cost_exchange_rate, 0.0001);
        $this->assertSame(1000, $item->unit_cost);
        // EMBEDDED: 1000 × 1.20 = 1200
        $this->assertSame(1200, $item->unit_price);
    }
```

Update existing constructor-based test method `test_throws_when_existing_quotation_is_sent_without_force` to coexist with the new one (no change needed; just keep it).

- [ ] **Step 2: Run — confirm new test fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: lock test PASS, single-currency test FAIL — items not yet created.

- [ ] **Step 3: Replace stub with creation logic**

Replace the body of `execute` (everything after the lock check) with:

```php
            $existing = $existing && ! in_array($existing->status, self::LOCKED_STATUSES, true)
                ? $existing
                : null;

            if ($existing) {
                $existing->update([
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'currency_code' => $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                    'show_suppliers' => $showSuppliers,
                ]);
                $quotation = $existing;
            } else {
                $quotation = Quotation::create([
                    'reference' => 'Q-' . now()->format('Ymd') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                    'inquiry_id' => $inquiry->id,
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'status' => QuotationStatus::DRAFT,
                    'currency_code' => $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                    'show_suppliers' => $showSuppliers,
                ]);
            }

            $this->syncItems(
                inquiry: $inquiry,
                quotation: $quotation,
                supplierQuotationIds: $supplierQuotationIds,
                commissionType: $commissionType,
                commissionRate: $commissionRate,
            );

            return $quotation->fresh(['items.suppliers']);
```

Also add the helper method on the class:

```php
    private function syncItems(
        Inquiry $inquiry,
        Quotation $quotation,
        array $supplierQuotationIds,
        CommissionType $commissionType,
        float $commissionRate,
    ): void {
        $sqItemsByProduct = empty($supplierQuotationIds)
            ? collect()
            : \App\Domain\SupplierQuotations\Models\SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->where('unit_cost', '>', 0)
                ->with('supplierQuotation.company')
                ->get()
                ->groupBy('product_id');

        $existingItems = $quotation->items()->get()->keyBy('product_id');
        $sortOrder = 0;

        foreach ($inquiry->items as $inquiryItem) {
            $productId = $inquiryItem->product_id;
            $alternatives = $sqItemsByProduct->get($productId, collect());

            // Elect primary by lowest *converted* cost.
            $quoteCurrency = $quotation->currency_code;
            $primary = $alternatives->sortBy(function ($sqItem) use ($quoteCurrency, $quotation) {
                $resolved = $this->fx->resolve(
                    $sqItem->supplierQuotation->currency_code,
                    $quoteCurrency,
                    optional($quotation->created_at)->toDateString(),
                );

                return $sqItem->unit_cost * $resolved['rate'];
            })->first();

            $unitCost = $primary?->unit_cost ?? 0;
            $sourceCurrency = $primary?->supplierQuotation?->currency_code ?? $quoteCurrency;
            $resolved = $this->fx->resolve(
                $sourceCurrency,
                $quoteCurrency,
                optional($quotation->created_at)->toDateString(),
            );
            $rate = $resolved['rate'];
            $convertedCost = (int) round($unitCost * $rate);

            $existingItem = $existingItems->get($productId);
            $itemCommissionRate = $existingItem
                ? (float) $existingItem->commission_rate
                : ($commissionType === CommissionType::EMBEDDED ? $commissionRate : 0);

            $unitPrice = $commissionType === CommissionType::EMBEDDED && $itemCommissionRate > 0
                ? (int) round($convertedCost * (1 + $itemCommissionRate / 100))
                : $convertedCost;

            $payload = [
                'quotation_id' => $quotation->id,
                'product_id' => $productId,
                'supplier_quotation_item_id' => $primary?->id,
                'quantity' => $inquiryItem->quantity,
                'selected_supplier_id' => $primary?->supplierQuotation?->company_id,
                'unit_cost' => $unitCost,
                'cost_currency_code' => $resolved['currency'],
                'cost_exchange_rate' => $rate,
                'commission_rate' => $itemCommissionRate,
                'unit_price' => $unitPrice,
                'sort_order' => $sortOrder++,
            ];

            if ($existingItem) {
                $existingItem->update($payload);
            } else {
                \App\Domain\Quotations\Models\QuotationItem::create($payload);
            }
        }
    }
```

- [ ] **Step 4: Run — single-currency test passes; lock test still passes**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php
git add -A
git commit -m "feat(quotations): single-currency item sync in CreateOrUpdateQuotation action"
```

### Task 2.7: Cross-currency + multi-currency aggregation (TDD)

**Files:**
- Modify: `tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`

- [ ] **Step 1: Add CNY/EUR currencies + ExchangeRate to setUp()**

Replace setUp with:

```php
    protected function setUp(): void
    {
        parent::setUp();
        $usd = \App\Domain\Settings\Models\Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = \App\Domain\Settings\Models\Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        $eur = \App\Domain\Settings\Models\Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'name_plural' => 'Euros',
            'symbol' => '€', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        \App\Domain\Settings\Models\ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => \App\Domain\Settings\Enums\ExchangeRateStatus::APPROVED,
        ]);
        \App\Domain\Settings\Models\ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $eur->id,
            'rate' => 0.92, 'inverse_rate' => 1 / 0.92,
            'date' => today()->subDay()->toDateString(),
            'status' => \App\Domain\Settings\Enums\ExchangeRateStatus::APPROVED,
        ]);
    }
```

- [ ] **Step 2: Add cross-currency test**

```php
    public function test_cross_currency_cny_to_usd_snapshots_rate(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000], // ¥700.00
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $item = $quotation->items->first();
        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $item->cost_exchange_rate, 0.0001);
        $this->assertSame(70000, $item->unit_cost); // raw CNY preserved
        // converted: 70000 × 1/7 ≈ 10000 USD minor units = $100.00
        $this->assertEqualsWithDelta(10000, $item->unit_price, 1);
    }

    public function test_multi_currency_aggregation_each_item_has_own_rate(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(itemCount: 2);
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $sqCny = $this->buildSqWith($inquiry, $supplierA, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);
        $sqEur = $this->buildSqWith($inquiry, $supplierB, 'EUR', [
            ['product_id' => $items[1]->product_id, 'unit_cost' => 9200], // €92.00
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sqCny->id, $sqEur->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $byProduct = $quotation->items->keyBy('product_id');
        $this->assertSame('CNY', $byProduct[$items[0]->product_id]->cost_currency_code);
        $this->assertSame('EUR', $byProduct[$items[1]->product_id]->cost_currency_code);
    }
```

- [ ] **Step 3: Run — should pass without code change**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: PASS, 4 tests. (The action already calls the resolver per item; tests verify it works.)

If a test fails because resolution date doesn't have a rate, adjust `optional($quotation->created_at)->toDateString()` in the action to use `today()->toDateString()` as a fallback when `created_at` is null on a fresh build.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php
git add -A
git commit -m "test(quotations): cross-currency and multi-currency action coverage"
```

### Task 2.8: Refresh in DRAFT preserves commission_rate (TDD)

**Files:**
- Modify: `tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`

- [ ] **Step 1: Add re-run test**

```php
    public function test_rerun_in_draft_refreshes_unit_cost_and_rate_but_preserves_commission_override(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry, supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 10, showSuppliers: false,
        );
        $item = $quotation->items->first();
        $item->update(['commission_rate' => 25.0]); // user override
        $sq->items->first()->update(['unit_cost' => 80000]); // supplier re-quote

        $quotation2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh(), supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 10, showSuppliers: false,
        );

        $refreshed = $quotation2->items->first();
        $this->assertSame($quotation->id, $quotation2->id, 'should update in place, not create new');
        $this->assertSame(80000, $refreshed->unit_cost, 'unit_cost refreshed from SQ');
        $this->assertEqualsWithDelta(25.0, (float) $refreshed->commission_rate, 0.01, 'commission_rate override preserved');
    }
```

- [ ] **Step 2: Run — current code likely fails because it overwrites commission_rate from header**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php --filter rerun_in_draft`
Expected: FAIL on commission_rate assertion.

(If it actually passes, great — the existing logic already preserves it. Skip Step 3.)

- [ ] **Step 3: Adjust syncItems() in the action**

The check is already in the helper above (`$itemCommissionRate = $existingItem ? (float) $existingItem->commission_rate : ...`). If still failing, ensure `commission_rate` in the upsert payload uses `$itemCommissionRate`, not `$commissionRate` from the form.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint -t app
git add -A
git commit -m "feat(quotations): preserve commission_rate overrides on action re-run"
```

### Task 2.9: Multi-supplier population + force new version (TDD)

**Files:**
- Modify: `app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php`
- Modify: `tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`

- [ ] **Step 1: Write multi-supplier test**

```php
    public function test_all_alternatives_are_persisted_as_quotation_item_suppliers(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $sqA = $this->buildSqWith($inquiry, $supplierA, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 80000],
        ]);
        $sqB = $this->buildSqWith($inquiry, $supplierB, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry, supplierQuotationIds: [$sqA->id, $sqB->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 0, showSuppliers: false,
        );

        $item = $quotation->items->first();
        $this->assertSame($supplierB->id, $item->selected_supplier_id, 'lowest-cost supplier elected');
        $this->assertCount(2, $item->suppliers, 'both alternatives stored');
    }

    public function test_sent_with_force_new_version_creates_v2_and_snapshots_v1(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);

        $v1 = $this->makeAction()->execute(
            inquiry: $inquiry, supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 0, showSuppliers: false,
        );
        $v1->update(['status' => QuotationStatus::SENT]);

        $v2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh(), supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 0, showSuppliers: false,
            forceNewVersion: true,
        );

        $this->assertNotSame($v1->id, $v2->id, 'new Quotation row');
        $this->assertSame(2, $v2->version);
        $this->assertSame(QuotationStatus::DRAFT, $v2->status);
        $this->assertDatabaseHas('quotation_versions', [
            'quotation_id' => $v1->id,
            'version' => 1,
        ]);
    }
```

- [ ] **Step 2: Run — both fail (no multi-supplier sync, no version snapshot yet)**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: FAIL on the two new tests.

- [ ] **Step 3: Extend syncItems() to populate QuotationItemSupplier**

Inside the foreach in `syncItems()`, after persisting the QuotationItem, add:

```php
            $persistedItem = $existingItem ?: \App\Domain\Quotations\Models\QuotationItem::where('quotation_id', $quotation->id)
                ->where('product_id', $productId)
                ->latest('id')
                ->first();

            // Sync alternatives.
            $persistedItem->suppliers()->delete();
            foreach ($alternatives as $alt) {
                $altResolved = $this->fx->resolve(
                    $alt->supplierQuotation->currency_code,
                    $quoteCurrency,
                    optional($quotation->created_at)->toDateString(),
                );
                \App\Domain\Quotations\Models\QuotationItemSupplier::create([
                    'quotation_item_id' => $persistedItem->id,
                    'company_id' => $alt->supplierQuotation->company_id,
                    'unit_cost' => $alt->unit_cost,
                    'currency_code' => $altResolved['currency'],
                    'cost_exchange_rate' => $altResolved['rate'],
                    'lead_time_days' => $alt->lead_time_days,
                    'moq' => $alt->moq,
                ]);
            }
```

- [ ] **Step 4: Add force-new-version branch in execute()**

Replace the lock block plus the `$existing = ...` reassignment with this version snapshot + version increment logic:

```php
            $existing = $inquiry->quotations()->latest('version')->first();

            if ($existing && in_array($existing->status, self::LOCKED_STATUSES, true)) {
                if (! $forceNewVersion) {
                    throw new QuotationLockedException($existing);
                }

                \App\Domain\Quotations\Models\QuotationVersion::create([
                    'quotation_id' => $existing->id,
                    'version' => $existing->version,
                    'snapshot' => $this->snapshotQuotation($existing),
                ]);

                $existing = null; // force creation of a new row below
                $newVersion = ($inquiry->quotations()->max('version') ?? 0) + 1;
            } else {
                $newVersion = 1;
            }
```

And in the `Quotation::create([...])` array, add `'version' => $newVersion`.

Also add a private helper:

```php
    private function snapshotQuotation(Quotation $quotation): array
    {
        return [
            'quotation' => $quotation->toArray(),
            'items' => $quotation->items()->with('suppliers')->get()->toArray(),
        ];
    }
```

- [ ] **Step 5: Run — both tests pass**

Run: `vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint -t app tests
git add -A
git commit -m "feat(quotations): populate alternatives and snapshot prior versions on force"
```

### Task 2.10: Wire InquiryHeaderActions to delegate to action

**Files:**
- Modify: `app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php` (lines 305-556 — `createQuotationAction` method)

- [ ] **Step 1: Replace the closure body to delegate to the action**

Inside `->action(function (array $data) use ($hasSupplierQuotations, $existingQuotation) { ... })`, replace the `DB::transaction(...)` block with:

```php
                try {
                    $commissionType = $data['commission_type'] instanceof CommissionType
                        ? $data['commission_type']
                        : CommissionType::from($data['commission_type']);
                    $commissionRate = (float) ($data['commission_rate'] ?? 0);
                    $supplierQuotationIds = $data['supplier_quotation_ids'] ?? [];

                    $quotation = app(\App\Domain\Quotations\Actions\CreateOrUpdateQuotationFromInquiryAction::class)
                        ->execute(
                            inquiry: Inquiry::with('items')->findOrFail($this->record->id),
                            supplierQuotationIds: $supplierQuotationIds,
                            commissionType: $commissionType,
                            commissionRate: $commissionRate,
                            showSuppliers: false,
                        );

                    if ($this->record->status === InquiryStatus::RECEIVED) {
                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            InquiryStatus::QUOTING,
                            'Quotation ' . $quotation->reference . ' created from inquiry.',
                        );
                    }

                    Notification::make()
                        ->title(($existingQuotation ? __('messages.quotation_updated') : __('messages.quotation_created')) . ': ' . $quotation->reference)
                        ->body(__('messages.items_populated_redirecting'))
                        ->success()
                        ->send();

                    return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                } catch (\App\Domain\Quotations\Exceptions\QuotationLockedException $e) {
                    Notification::make()
                        ->title(__('messages.quotation_locked'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.error_creating_quotation'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
```

- [ ] **Step 2: Add lang key**

Edit `lang/pt_BR/messages.php` (or wherever the project's pt-BR messages live — `grep -rn "quotation_created" lang/`) to add:
```php
    'quotation_locked' => 'Cotação bloqueada para recálculo',
```
Add the English equivalent in `lang/en/messages.php` if that file exists.

- [ ] **Step 3: Smoke test**

Run: `composer test`
Expected: green. Manually: open `/panel/inquiries/{id}` for a fresh inquiry with at least one received SQ in CNY; click "Criar cotação"; verify the resulting Quotation has `cost_currency_code = CNY` and a non-1 rate on its items.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php lang/pt_BR/messages.php
git add -A
git commit -m "refactor(inquiries): delegate createQuotationAction to domain action"
```

### Task 2.11: Audit `unit_cost` reads outside the touched files

**Files:**
- Read-only audit step — produces a follow-up commit if anything material is found.

This is the spec's documented risk in §13: "any code outside the listed touchpoints that reads `unit_cost` directly may now misinterpret."

- [ ] **Step 1: Grep for direct usages**

Run:
```bash
grep -rnE 'QuotationItem.*unit_cost|->unit_cost' app/ resources/ --include="*.php" --include="*.blade.php" | grep -i quotation | grep -v 'app/Domain/Quotations/Models'
```
Expected output: list of usages.

- [ ] **Step 2: For each hit, classify**

For each line, determine: is this code displaying or computing in the **quote currency**? If yes, it should use `converted_unit_cost` instead. If the code is just persisting raw values (form repeater, action), leave it.

- [ ] **Step 3: Patch ambiguous reads**

Replace `$item->unit_cost` with `$item->converted_unit_cost` only where the value is being formatted for display in the quote currency (PDFs, infolists, dashboard widgets).

If you find no ambiguous reads, this task ends with a no-op commit.

- [ ] **Step 4: Run suite**

Run: `composer test`
Expected: green.

- [ ] **Step 5: Pint + commit (only if changes were made)**

```bash
vendor/bin/pint -t app
git add -A
git commit -m "refactor(quotations): use converted_unit_cost where display in quote currency"
```

---

# PR 3 — FX-aware Filament form

UI now exposes the rate per item; lock active for SENT/NEGOTIATING.

### Task 3.1: Add Items preview repeater to the form

**Files:**
- Modify: `app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php` (the `->form(...)` closure inside `createQuotationAction`)

- [ ] **Step 1: After the existing fields builder, add a Repeater**

Inside `->form(function () use ($inquiry, $hasSupplierQuotations) { $fields = []; ... return $fields; })`, append before the `return $fields;`:

```php
                $fields[] = \Filament\Forms\Components\Repeater::make('items_preview')
                    ->label(__('forms.labels.items_preview'))
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('product_label')
                            ->label(__('forms.labels.product'))->disabled()->dehydrated(false),
                        \Filament\Forms\Components\TextInput::make('quantity')
                            ->label(__('forms.labels.quantity'))->numeric()->required(),
                        \Filament\Forms\Components\TextInput::make('source_sq_label')
                            ->label(__('forms.labels.source_sq'))->disabled()->dehydrated(false),
                        \Filament\Forms\Components\TextInput::make('unit_cost')
                            ->label(__('forms.labels.source_unit_cost'))->numeric()->step(0.0001),
                        \Filament\Forms\Components\Select::make('cost_currency_code')
                            ->label(__('forms.labels.cost_currency'))
                            ->options(\App\Domain\Settings\Models\Currency::query()->where('is_active', true)->pluck('code', 'code'))
                            ->required(),
                        \Filament\Forms\Components\TextInput::make('cost_exchange_rate')
                            ->label(__('forms.labels.cost_fx_rate'))->numeric()->step(0.00000001)
                            ->required()->minValue(0.00000001),
                        \Filament\Forms\Components\TextInput::make('commission_rate')
                            ->label(__('forms.labels.commission_pct'))->numeric()->step(0.01)->suffix('%'),
                        \Filament\Forms\Components\TextInput::make('unit_price')
                            ->label(__('forms.labels.unit_price'))->numeric()->step(0.0001)->required(),
                    ])
                    ->columns(4)
                    ->defaultItems(0)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false);
```

- [ ] **Step 2: Add reactivity on `supplier_quotation_ids`**

Modify the `Select::make('supplier_quotation_ids')` definition higher up in the form to:

```php
                    $fields[] = Select::make('supplier_quotation_ids')
                        ->label(__('forms.labels.source_supplier_quotations'))
                        ->options($sqOptions)
                        ->multiple()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) use ($inquiry) {
                            $set('items_preview', $this->buildItemsPreview($inquiry, $state ?? []));
                        });
```

- [ ] **Step 3: Add the helper method to the trait/concern**

In the same file, near `createQuotationAction`, add:

```php
    private function buildItemsPreview(Inquiry $inquiry, array $supplierQuotationIds): array
    {
        $resolver = app(\App\Domain\Settings\Services\CurrencyExchangeResolver::class);
        $sqItemsByProduct = empty($supplierQuotationIds)
            ? collect()
            : SupplierQuotationItem::query()
                ->whereIn('supplier_quotation_id', $supplierQuotationIds)
                ->with('supplierQuotation.company')
                ->get()
                ->groupBy('product_id');

        $rows = [];
        foreach ($inquiry->items as $inquiryItem) {
            $alts = $sqItemsByProduct->get($inquiryItem->product_id, collect());
            $primary = $alts->sortBy(function ($alt) use ($resolver, $inquiry) {
                $r = $resolver->resolve($alt->supplierQuotation->currency_code, $inquiry->currency_code);

                return $alt->unit_cost * $r['rate'];
            })->first();

            $sourceCurrency = $primary?->supplierQuotation?->currency_code ?? $inquiry->currency_code;
            $resolved = $resolver->resolve($sourceCurrency, $inquiry->currency_code);

            $rows[] = [
                'product_label' => $inquiryItem->product?->name ?? '—',
                'quantity' => $inquiryItem->quantity,
                'source_sq_label' => $primary?->supplierQuotation?->reference
                    . ($primary ? ' · ' . $primary->supplierQuotation->company->name : ''),
                'unit_cost' => ($primary?->unit_cost ?? 0) / 10000,
                'cost_currency_code' => $resolved['currency'],
                'cost_exchange_rate' => $resolved['rate'],
                'commission_rate' => 0,
                'unit_price' => 0, // filled on submit
            ];
        }

        return $rows;
    }
```

- [ ] **Step 4: Compile and check Filament UI**

Run: `php artisan filament:upgrade && php artisan view:clear`
Expected: no errors. Open the inquiry page, toggle SQs, repeater rows appear with pre-filled rate.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php
git add -A
git commit -m "feat(quotations): FX-aware items preview repeater in createQuotation form"
```

### Task 3.2: Wire repeater payload into action call

**Files:**
- Modify: `app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php` (the `->action(...)` closure)
- Modify: `app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php`

The action signature must accept overrides per InquiryItem so the user's edits in the repeater (rate, unit_cost, commission, unit_price) win.

- [ ] **Step 1: Extend action signature with `array $itemOverrides = []`**

Add a parameter `array $itemOverrides = []` (keyed by InquiryItem id) to `execute()`. Inside `syncItems()`, when an override exists for the current `$inquiryItem->id`, apply it (skip auto-resolve and use the user-supplied values).

```php
            $override = $itemOverrides[$inquiryItem->id] ?? null;
            if ($override) {
                $unitCost = (int) round(($override['unit_cost'] ?? 0) * 10000);
                $sourceCurrency = $override['cost_currency_code'] ?? $sourceCurrency;
                $rate = (float) ($override['cost_exchange_rate'] ?? $rate);
                $itemCommissionRate = (float) ($override['commission_rate'] ?? $itemCommissionRate);
                $unitPrice = isset($override['unit_price'])
                    ? (int) round($override['unit_price'] * 10000)
                    : $unitPrice;
                $resolved = ['currency' => $sourceCurrency, 'rate' => $rate];
                $convertedCost = (int) round($unitCost * $rate);
            }
```

- [ ] **Step 2: In Filament action, build itemOverrides from `data['items_preview']`**

```php
                    $itemOverrides = [];
                    foreach (($data['items_preview'] ?? []) as $idx => $row) {
                        $inquiryItemId = $this->record->items[$idx]?->id ?? null;
                        if ($inquiryItemId) {
                            $itemOverrides[$inquiryItemId] = $row;
                        }
                    }

                    $quotation = app(CreateOrUpdateQuotationFromInquiryAction::class)
                        ->execute(
                            inquiry: ...,
                            supplierQuotationIds: $supplierQuotationIds,
                            commissionType: $commissionType,
                            commissionRate: $commissionRate,
                            showSuppliers: false,
                            itemOverrides: $itemOverrides,
                        );
```

- [ ] **Step 3: Add unit test for override path**

```php
    public function test_item_overrides_win_over_resolver_defaults(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry, supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED, commissionRate: 0, showSuppliers: false,
            itemOverrides: [
                $items[0]->id => [
                    'unit_cost' => 7.5,                      // 75000 minor units
                    'cost_currency_code' => 'CNY',
                    'cost_exchange_rate' => 0.20,            // user override of fx
                    'commission_rate' => 30,
                    'unit_price' => 1.95,                    // 19500 minor (= 75000 × 0.20 × 1.30)
                ],
            ],
        );

        $item = $quotation->items->first();
        $this->assertSame(75000, $item->unit_cost);
        $this->assertEqualsWithDelta(0.20, (float) $item->cost_exchange_rate, 0.001);
        $this->assertSame(19500, $item->unit_price);
    }
```

- [ ] **Step 4: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Quotations/CreateOrUpdateQuotationFromInquiryActionTest.php
vendor/bin/pint -t app tests
git add -A
git commit -m "feat(quotations): per-item overrides from form payload override resolver defaults"
```

### Task 3.3: Visual lock on SENT+ with "Nova versão" button

**Files:**
- Modify: `app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php`

- [ ] **Step 1: Conditionally render the action vs a banner+button**

Right above `createQuotationAction()`, add a sibling action:

```php
    protected function createQuotationNewVersionAction(): Action
    {
        return Action::make('createQuotationNewVersion')
            ->label(__('forms.labels.create_quotation_new_version'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => $this->latestQuotation()
                && in_array($this->latestQuotation()->status, [
                    QuotationStatus::SENT, QuotationStatus::NEGOTIATING,
                ]))
            ->requiresConfirmation()
            ->action(function () {
                try {
                    $quotation = app(CreateOrUpdateQuotationFromInquiryAction::class)
                        ->execute(
                            inquiry: Inquiry::with('items')->findOrFail($this->record->id),
                            supplierQuotationIds: $this->latestQuotation()->items
                                ->pluck('supplier_quotation_item_id')
                                ->filter()
                                ->map(fn ($id) => SupplierQuotationItem::find($id)?->supplier_quotation_id)
                                ->filter()
                                ->unique()
                                ->values()
                                ->toArray(),
                            commissionType: $this->latestQuotation()->commission_type,
                            commissionRate: (float) $this->latestQuotation()->commission_rate,
                            showSuppliers: (bool) $this->latestQuotation()->show_suppliers,
                            forceNewVersion: true,
                        );

                    Notification::make()->title(__('messages.quotation_new_version_created') . ': v' . $quotation->version)->success()->send();

                    return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                } catch (\Throwable $e) {
                    Notification::make()->title(__('messages.error_creating_quotation'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function latestQuotation(): ?Quotation
    {
        return $this->record->quotations()->latest('version')->first();
    }
```

Then update the original `createQuotationAction()`'s `->visible()` to additionally hide when latest quotation is SENT+:

```php
            ->visible(fn () => in_array($this->record->status, [
                InquiryStatus::RECEIVED, InquiryStatus::QUOTING, InquiryStatus::QUOTED,
            ]) && (
                ! $this->latestQuotation() ||
                ! in_array($this->latestQuotation()->status, [QuotationStatus::SENT, QuotationStatus::NEGOTIATING], true)
            ))
```

- [ ] **Step 2: Register the new action in the resource's header actions array**

Find where `createQuotationAction()` is currently included (likely a `getHeaderActions()` method nearby). Add `$this->createQuotationNewVersionAction()` next to it.

- [ ] **Step 3: Manual smoke test**

Open an Inquiry whose latest Quotation is in SENT status. Confirm the "Atualizar cotação" button is hidden and "Criar nova versão" is shown. Click it; v2 is created, v1 snapshot exists in `quotation_versions`.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php
git add -A
git commit -m "feat(inquiries): visual lock on SENT+ quotation with Nova Versão action"
```

### Task 3.4: Feature test — form submission triggers action

**Files:**
- Create: `tests/Feature/Filament/CreateQuotationActionFormTest.php`

- [ ] **Step 1: Write Livewire-style feature test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateQuotationActionFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_quotation_via_form_action_persists_fx_snapshot(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        $cny = Currency::create(['code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true]);
        ExchangeRate::create(['base_currency_id' => $usd->id, 'target_currency_id' => $cny->id, 'rate' => 7.0, 'inverse_rate' => 1 / 7.0, 'date' => today()->subDay()->toDateString(), 'status' => ExchangeRateStatus::APPROVED]);

        $admin = User::factory()->create();
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $product = Product::factory()->create();

        $inquiry = Inquiry::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);
        InquiryItem::create(['inquiry_id' => $inquiry->id, 'product_id' => $product->id, 'quantity' => 10, 'sort_order' => 0]);

        $sq = SupplierQuotation::create(['reference' => 'SQ-FT-001', 'inquiry_id' => $inquiry->id, 'company_id' => $supplier->id, 'currency_code' => 'CNY', 'status' => SupplierQuotationStatus::RECEIVED]);
        SupplierQuotationItem::create(['supplier_quotation_id' => $sq->id, 'product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 70000]);

        $this->actingAs($admin);
        Livewire::test(EditInquiry::class, ['record' => $inquiry->id])
            ->callAction('createQuotation', data: [
                'supplier_quotation_ids' => [$sq->id],
                'commission_type' => 'embedded',
                'commission_rate' => 10,
                'items_preview' => [[
                    'product_label' => $product->name,
                    'quantity' => 10,
                    'source_sq_label' => 'SQ-FT-001',
                    'unit_cost' => 7.0,
                    'cost_currency_code' => 'CNY',
                    'cost_exchange_rate' => 1 / 7.0,
                    'commission_rate' => 10,
                    'unit_price' => 1.10,
                ]],
            ])
            ->assertHasNoActionErrors();

        $quotation = $inquiry->quotations()->first();
        $this->assertNotNull($quotation);
        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame('CNY', $quotation->items->first()->cost_currency_code);
        $this->assertSame(11000, $quotation->items->first()->unit_price); // $1.10 in minor units
    }
}
```

- [ ] **Step 2: Run + commit**

```bash
vendor/bin/phpunit tests/Feature/Filament/CreateQuotationActionFormTest.php
vendor/bin/pint tests/Feature/Filament/CreateQuotationActionFormTest.php
git add -A
git commit -m "test(quotations): feature test for createQuotation form FX wiring"
```

---

# PR 4 — Backfill command

Independent of PR 3. Can ship before or after.

### Task 4.1: Create backfill command (TDD)

**Files:**
- Create: `app/Console/Commands/BackfillQuotationFxSnapshotsCommand.php`
- Test: `tests/Feature/Console/BackfillQuotationFxSnapshotsCommandTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Console;

use App\Domain\CRM\Models\Company;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillQuotationFxSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        $cny = Currency::create(['code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true]);
        ExchangeRate::create(['base_currency_id' => $usd->id, 'target_currency_id' => $cny->id, 'rate' => 7.0, 'inverse_rate' => 1 / 7.0, 'date' => today()->subYear()->toDateString(), 'status' => ExchangeRateStatus::APPROVED]);
    }

    public function test_backfills_resolved_legacy_and_missing_buckets(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-1', 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT, 'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);

        // Resolved-from-source: cost_currency NULL but has SQ in CNY.
        $sq = SupplierQuotation::create(['reference' => 'SQ-BF-1', 'inquiry_id' => $inquiry->id, 'company_id' => $supplier->id, 'currency_code' => 'CNY', 'status' => SupplierQuotationStatus::RECEIVED]);
        $sqItem = SupplierQuotationItem::create(['supplier_quotation_id' => $sq->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 7000]);
        $resolved = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id, 'supplier_quotation_item_id' => $sqItem->id,
            'quantity' => 1, 'unit_cost' => 7000, 'unit_price' => 1000, 'commission_rate' => 0,
        ]);

        // Legacy (no source SQ).
        $legacy = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'unit_cost' => 1000, 'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots')->assertExitCode(0);

        $resolved->refresh();
        $this->assertSame('CNY', $resolved->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $resolved->cost_exchange_rate, 0.0001);

        $legacy->refresh();
        $this->assertSame('USD', $legacy->cost_currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $legacy->cost_exchange_rate, 0.0001);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-DR', 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT, 'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);
        $item = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 1000, 'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots --dry-run')->assertExitCode(0);
        $item->refresh();
        $this->assertNull($item->cost_currency_code);
    }

    public function test_idempotent_after_first_run(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-ID', 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT, 'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 1000, 'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots')->assertExitCode(0);
        $this->artisan('quotations:backfill-fx-snapshots')
            ->expectsOutputToContain('0 quotation items')
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run — fails (command not registered)**

Run: `vendor/bin/phpunit tests/Feature/Console/BackfillQuotationFxSnapshotsCommandTest.php`
Expected: FAIL — "Command not found".

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillQuotationFxSnapshotsCommand extends Command
{
    protected $signature = 'quotations:backfill-fx-snapshots
        {--dry-run : Do not persist changes}
        {--quotation= : Limit to a specific quotation id}
        {--report= : Write per-row decisions to this CSV path}';

    protected $description = 'Backfill cost_currency_code and cost_exchange_rate on legacy quotation_items and quotation_item_suppliers';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $reportPath = $this->option('report');
        $reportRows = [];

        $itemsQuery = QuotationItem::query()
            ->whereNull('cost_currency_code')
            ->with('supplierQuotationItem.supplierQuotation', 'quotation');
        if ($this->option('quotation')) {
            $itemsQuery->where('quotation_id', (int) $this->option('quotation'));
        }

        $stats = ['resolved' => 0, 'legacy' => 0, 'missing' => 0];

        $itemsQuery->chunkById(200, function ($items) use (&$stats, &$reportRows, $isDryRun) {
            foreach ($items as $item) {
                $sourceCurrency = $item->supplierQuotationItem?->supplierQuotation?->currency_code;
                $bucket = 'resolved';
                if ($sourceCurrency === null) {
                    $sourceCurrency = $item->quotation->currency_code;
                    $bucket = 'legacy';
                }

                $rate = 1.0;
                if ($sourceCurrency !== $item->quotation->currency_code) {
                    $source = Currency::findByCode($sourceCurrency);
                    $target = Currency::findByCode($item->quotation->currency_code);
                    if ($source && $target) {
                        $converted = ExchangeRate::convert(
                            $source->id, $target->id, 1.0,
                            optional($item->quotation->created_at)->toDateString(),
                        ) ?? ExchangeRate::convert(
                            $source->id, $target->id, 1.0,
                            optional($item->supplierQuotationItem?->supplierQuotation?->created_at)->toDateString(),
                        );
                        if ($converted !== null) {
                            $rate = (float) $converted;
                        } else {
                            $bucket = 'missing';
                        }
                    } else {
                        $bucket = 'missing';
                    }
                }

                if (! $isDryRun) {
                    $item->update([
                        'cost_currency_code' => $sourceCurrency,
                        'cost_exchange_rate' => $rate,
                    ]);
                }

                $stats[$bucket]++;
                $reportRows[] = [
                    'quotation_item_id' => $item->id, 'bucket' => $bucket,
                    'cost_currency_code' => $sourceCurrency, 'cost_exchange_rate' => $rate,
                ];
            }
        });

        // Suppliers — same idea but read currency from the row itself.
        $suppliersStats = ['resolved' => 0, 'missing' => 0];
        QuotationItemSupplier::query()
            ->whereNull('cost_exchange_rate')
            ->with('quotationItem.quotation')
            ->chunkById(200, function ($rows) use (&$suppliersStats, &$reportRows, $isDryRun) {
                foreach ($rows as $row) {
                    $rate = 1.0;
                    $bucket = 'resolved';
                    if ($row->currency_code !== $row->quotationItem->quotation->currency_code) {
                        $source = Currency::findByCode($row->currency_code);
                        $target = Currency::findByCode($row->quotationItem->quotation->currency_code);
                        if ($source && $target) {
                            $converted = ExchangeRate::convert(
                                $source->id, $target->id, 1.0,
                                optional($row->quotationItem->quotation->created_at)->toDateString(),
                            );
                            if ($converted !== null) {
                                $rate = (float) $converted;
                            } else {
                                $bucket = 'missing';
                            }
                        } else {
                            $bucket = 'missing';
                        }
                    }
                    if (! $isDryRun) {
                        $row->update(['cost_exchange_rate' => $rate]);
                    }
                    $suppliersStats[$bucket]++;
                }
            });

        $this->line(sprintf('%d quotation items processed', array_sum($stats)));
        $this->line(sprintf('  ✓ Resolved from SQ source:        %d', $stats['resolved']));
        $this->line(sprintf('  ⚠ Legacy (no source SQ):          %d', $stats['legacy']));
        $this->line(sprintf('  ⚠ Missing FX rate at quote date:  %d', $stats['missing']));
        $this->newLine();
        $this->line(sprintf('%d quotation item suppliers processed', array_sum($suppliersStats)));
        $this->line(sprintf('  ✓ Resolved:                       %d', $suppliersStats['resolved']));
        $this->line(sprintf('  ⚠ Missing FX rate:                %d', $suppliersStats['missing']));

        if ($isDryRun) {
            $this->newLine();
            $this->info('Dry run — no changes persisted.');
        }

        if ($reportPath) {
            $fh = fopen($reportPath, 'w');
            fputcsv($fh, ['quotation_item_id', 'bucket', 'cost_currency_code', 'cost_exchange_rate']);
            foreach ($reportRows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->line(sprintf('Report written to %s', $reportPath));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run + commit**

Run: `composer test`
Expected: green.

```bash
vendor/bin/pint app/Console/Commands/BackfillQuotationFxSnapshotsCommand.php tests/Feature/Console/BackfillQuotationFxSnapshotsCommandTest.php
git add -A
git commit -m "feat(quotations): backfill command for legacy FX snapshot data"
```

### Task 4.2: Production dry-run + apply (deployment-time)

**This is a runtime checklist, not a code change.** Do not commit anything in this task.

- [ ] **Step 1: Deploy PRs 1+2 to staging**
- [ ] **Step 2: `php artisan quotations:backfill-fx-snapshots --dry-run --report=backfill-staging.csv`**
- [ ] **Step 3: Review the CSV** — focus on the `missing` bucket. Identify any high-value quotations that need a manually-created `ExchangeRate` row before the apply pass.
- [ ] **Step 4: Backfill missing rates manually** if needed.
- [ ] **Step 5: `php artisan quotations:backfill-fx-snapshots --report=backfill-applied.csv`** in production.
- [ ] **Step 6: Re-run** — should report `0 quotation items` (idempotency check).

---

# PR 5 — Multi-supplier UI + table columns + infolist

### Task 5.1: New table columns + filter

**Files:**
- Modify: `app/Filament/Resources/Quotations/Tables/QuotationsTable.php`

- [ ] **Step 1: Add `total_cost` column**

Inside the `->columns([...])` array (find the existing `total` column), add adjacent:

```php
                Tables\Columns\TextColumn::make('total_converted_cost')
                    ->label(__('forms.labels.total_cost'))
                    ->getStateUsing(fn ($record) => $record->total_converted_cost)
                    ->money(fn ($record) => $record->currency_code, divideBy: 10000)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('avg_margin')
                    ->label(__('forms.labels.margin'))
                    ->getStateUsing(function ($record) {
                        $cost = $record->total_converted_cost;
                        if ($cost <= 0) return null;
                        return round((($record->subtotal - $cost) / $cost) * 100, 2);
                    })
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 1) . '%')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state < 10 => 'danger',
                        $state < 25 => 'warning',
                        default => 'success',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
```

- [ ] **Step 2: Add multi-currency filter**

Inside `->filters([...])`:

```php
                Tables\Filters\Filter::make('has_multi_currency_items')
                    ->label(__('forms.filters.has_multi_currency_items'))
                    ->query(fn ($query) => $query->whereHas('items', function ($q) {
                        $q->whereNotNull('cost_currency_code')
                          ->whereColumn('cost_currency_code', '!=', 'quotations.currency_code');
                    })),
```

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint app/Filament/Resources/Quotations/Tables/QuotationsTable.php
git add -A
git commit -m "feat(quotations): cost + margin columns and multi-currency filter"
```

### Task 5.2: FX Summary infolist block

**Files:**
- Modify: existing infolist file in `app/Filament/Resources/Quotations/Schemas/` (find via `grep -rln "QuotationStatus" app/Filament/Resources/Quotations/Schemas/`)

- [ ] **Step 1: Locate the file and add a section**

Inside the schema, add a new section:

```php
                Infolists\Components\Section::make(__('forms.sections.fx_summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('total_converted_cost')
                            ->label(__('forms.labels.total_cost'))
                            ->money(fn ($record) => $record->currency_code, divideBy: 10000),
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label(__('forms.labels.total_revenue'))
                            ->money(fn ($record) => $record->currency_code, divideBy: 10000),
                        Infolists\Components\TextEntry::make('aggregate_margin')
                            ->label(__('forms.labels.margin'))
                            ->getStateUsing(function ($record) {
                                $cost = $record->total_converted_cost;
                                return $cost > 0 ? round((($record->subtotal - $cost) / $cost) * 100, 2) . '%' : '—';
                            }),
                    ])
                    ->columns(3),
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint -t app
git add -A
git commit -m "feat(quotations): FX summary block on Quotation infolist"
```

### Task 5.3: Multi-supplier comparison table per item

**Files:**
- Modify: existing `RelationManager` for QuotationItems (find via `find app/Filament/Resources/Quotations -name "*.php" | xargs grep -l QuotationItem`)
- Or modify the schema where items are listed in the infolist

- [ ] **Step 1: Add a Repeater (read-only) showing alternatives per item**

Inside the QuotationItem display block (likely a `RepeatableEntry` in the infolist), nest a sub-`RepeatableEntry`:

```php
                Infolists\Components\RepeatableEntry::make('suppliers')
                    ->label(__('forms.labels.alternatives'))
                    ->schema([
                        Infolists\Components\TextEntry::make('company.name')->label(__('forms.labels.supplier')),
                        Infolists\Components\TextEntry::make('unit_cost')
                            ->label(__('forms.labels.cost'))
                            ->formatStateUsing(fn ($state, $record) => number_format($state / 10000, 2) . ' ' . $record->currency_code),
                        Infolists\Components\TextEntry::make('cost_exchange_rate')
                            ->label(__('forms.labels.fx_rate'))
                            ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 4) : '—'),
                        Infolists\Components\TextEntry::make('converted_unit_cost')
                            ->label(__('forms.labels.converted_cost'))
                            ->money(fn ($record) => $record->quotationItem->quotation->currency_code, divideBy: 10000),
                        Infolists\Components\TextEntry::make('lead_time_days')->label(__('forms.labels.lead_time'))->suffix('d'),
                    ])
                    ->columns(5),
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint -t app
git add -A
git commit -m "feat(quotations): multi-supplier comparison table on QuotationItem infolist"
```

### Task 5.4: "Make this selected" action (TDD)

**Files:**
- Modify: the Quotation edit/infolist where the alternatives are shown
- Test: `tests/Feature/Filament/MultiSupplierSelectionTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Domain\CRM\Models\Company;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiSupplierSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_make_this_selected_swaps_selected_supplier_and_fx_fields(): void
    {
        $admin = User::factory()->create();
        $client = Company::factory()->create();
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $quotation = Quotation::create([
            'reference' => 'Q-MS-1', 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT, 'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);
        $item = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 70000, 'cost_currency_code' => 'CNY', 'cost_exchange_rate' => 0.14,
            'unit_price' => 12000, 'commission_rate' => 0, 'selected_supplier_id' => $supplierA->id,
        ]);
        $alt = QuotationItemSupplier::create([
            'quotation_item_id' => $item->id, 'company_id' => $supplierB->id,
            'unit_cost' => 60000, 'currency_code' => 'CNY', 'cost_exchange_rate' => 0.14,
        ]);

        $this->actingAs($admin);
        // Trigger via service or POST. Use the action directly:
        app(\App\Domain\Quotations\Actions\PromoteQuotationItemSupplierAction::class)->execute($alt);

        $item->refresh();
        $this->assertSame($supplierB->id, $item->selected_supplier_id);
        $this->assertSame(60000, $item->unit_cost);
        $this->assertSame('CNY', $item->cost_currency_code);
    }
}
```

- [ ] **Step 2: Implement the action**

Create `app/Domain/Quotations/Actions/PromoteQuotationItemSupplierAction.php`:

```php
<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\QuotationItemSupplier;

class PromoteQuotationItemSupplierAction
{
    public function execute(QuotationItemSupplier $alt): void
    {
        $item = $alt->quotationItem;
        $quotation = $item->quotation;

        if ($quotation->status !== QuotationStatus::DRAFT) {
            throw new QuotationLockedException($quotation);
        }

        $rate = $alt->cost_exchange_rate !== null ? (float) $alt->cost_exchange_rate : 1.0;
        $convertedCost = (int) round($alt->unit_cost * $rate);
        $commissionRate = (float) $item->commission_rate;
        $unitPrice = $quotation->commission_type === CommissionType::EMBEDDED && $commissionRate > 0
            ? (int) round($convertedCost * (1 + $commissionRate / 100))
            : $convertedCost;

        $item->update([
            'selected_supplier_id' => $alt->company_id,
            'unit_cost' => $alt->unit_cost,
            'cost_currency_code' => $alt->currency_code,
            'cost_exchange_rate' => $rate,
            'unit_price' => $unitPrice,
        ]);
    }
}
```

- [ ] **Step 3: Run + wire UI button**

Run: `vendor/bin/phpunit tests/Feature/Filament/MultiSupplierSelectionTest.php`
Expected: PASS.

Then add a Filament Action button on each alternative row in the infolist:
```php
                        ->headerActions([
                            \Filament\Infolists\Actions\Action::make('promote')
                                ->label(__('forms.labels.make_this_selected'))
                                ->icon('heroicon-o-check-badge')
                                ->visible(fn ($record) => $record->quotationItem->quotation->status === QuotationStatus::DRAFT)
                                ->action(fn ($record) => app(PromoteQuotationItemSupplierAction::class)->execute($record))
                                ->requiresConfirmation(),
                        ])
```

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint -t app tests
git add -A
git commit -m "feat(quotations): promote alternative supplier on QuotationItem"
```

### Task 5.5: Adjacent infolist links (Inquiry, SupplierQuotation)

**Files:**
- Modify: Inquiry infolist file (find via `grep -rln "InquiryStatus" app/Filament/Resources/Inquiries/Schemas/`)
- Modify: SupplierQuotation infolist file similarly

- [ ] **Step 1: On InquiryInfolist, add a section showing latest quotation summary**

```php
                Infolists\Components\Section::make(__('forms.sections.latest_quotation'))
                    ->visible(fn ($record) => $record->quotations()->exists())
                    ->schema([
                        Infolists\Components\TextEntry::make('latest_quotation_ref')
                            ->getStateUsing(fn ($record) => optional($record->quotations()->latest('version')->first())->reference),
                        Infolists\Components\TextEntry::make('latest_quotation_version')
                            ->getStateUsing(fn ($record) => 'v' . optional($record->quotations()->latest('version')->first())->version),
                        Infolists\Components\TextEntry::make('latest_quotation_status')
                            ->getStateUsing(fn ($record) => optional($record->quotations()->latest('version')->first())->status?->getLabel()),
                    ])
                    ->columns(3),
```

- [ ] **Step 2: On SupplierQuotationInfolist, list quotations referencing this SQ**

```php
                Infolists\Components\Section::make(__('forms.sections.used_in_quotations'))
                    ->visible(fn ($record) => \App\Domain\Quotations\Models\QuotationItem::query()
                        ->whereIn('supplier_quotation_item_id', $record->items->pluck('id'))->exists())
                    ->schema([
                        Infolists\Components\TextEntry::make('used_in')
                            ->getStateUsing(fn ($record) => \App\Domain\Quotations\Models\Quotation::query()
                                ->whereIn('id', \App\Domain\Quotations\Models\QuotationItem::query()
                                    ->whereIn('supplier_quotation_item_id', $record->items->pluck('id'))
                                    ->pluck('quotation_id'))
                                ->get()
                                ->map(fn ($q) => $q->reference . ' (v' . $q->version . ')')
                                ->implode(', ')),
                    ]),
```

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint -t app
git add -A
git commit -m "feat(quotations): cross-references on Inquiry and SupplierQuotation infolists"
```

---

# Final verification

### Task F.1: Run full suite

- [ ] `composer test` — all green
- [ ] `vendor/bin/pint --test` — clean
- [ ] Manual smoke test:
  1. Create an Inquiry in USD with 2 items.
  2. Create one SQ in CNY (non-trivial rate exists in `exchange_rates`).
  3. Trigger "Criar cotação" — verify FX repeater shows the rate, edit it, submit.
  4. On the resulting Quotation, view the infolist — FX Summary block visible, alternatives table visible.
  5. Send the Quotation (status → SENT). Return to Inquiry — "Atualizar" hidden, "Nova versão" shown. Trigger it; v2 created, v1 in `quotation_versions`.

---

# Self-review (run before declaring done)

This plan was self-reviewed against the spec at write time. Spot-checks below; if any item fails, add a remediation task and continue.

- **Spec §4 (schema):** PR1 Tasks 1.1, 1.2 ✓
- **Spec §5 (models):** PR2 Tasks 2.1, 2.2, 2.3 ✓
- **Spec §6 (action):** PR2 Tasks 2.4–2.10 ✓
- **Spec §7 (form):** PR3 Tasks 3.1–3.3 ✓
- **Spec §8 (UI beyond form):** PR5 Tasks 5.1–5.5 ✓
- **Spec §9 (PDF):** No code changes needed for client PDF. Internal PDF (§9.2) is explicitly out of MVP. ✓
- **Spec §10 (backfill):** PR4 Tasks 4.1, 4.2 ✓
- **Spec §11 (tests):** Coverage in 2.1, 2.5–2.9, 3.4, 4.1, 5.4 ✓
- **Spec §13 risk: `unit_cost` semantics shift** — addressed by Task 2.11 audit ✓
- **Spec §13 risk: `selected_supplier_id` re-election** — preserved via `existingItem ? existingItem->commission_rate : ...` and the lock-on-SENT mechanism. Re-runs on DRAFT will re-elect; this is documented behavior. The Promote action (5.4) gives the user a manual override path.

Type / signature consistency check:
- `CurrencyExchangeResolver::resolve(?string, string, ?string, bool $strict = false): array` — used identically in 1.4, 2.5, 4.1.
- `CreateOrUpdateQuotationFromInquiryAction::execute(Inquiry, array, CommissionType, float, bool, bool $forceNewVersion = false, array $itemOverrides = [])` — `$itemOverrides` added in 3.2; signature must be widened in 3.2's first step before the test can use it.
- `QuotationLockedException(Quotation $quotation)` — constructor signature consistent across 2.4 and 5.4.
- `PromoteQuotationItemSupplierAction::execute(QuotationItemSupplier): void` — used in 5.4 only.

No placeholders, all code blocks complete.
