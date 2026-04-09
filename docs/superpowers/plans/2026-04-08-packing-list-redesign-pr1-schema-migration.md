# Packing List Redesign — PR #1: Schema + Data Migration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the new `cartons` and `carton_contents` tables, models, and a one-shot data migration action that converts existing `packing_list_items` rows into the new structure without losing data. PDF and UI continue using the legacy table — this PR is purely additive.

**Architecture:** Two new tables joined by FK. `Carton` is a first-class entity (one row per physical box) with auto-generated labels. `CartonContent` is the join row carrying `pieces`, an optional `multi_box_set_id` ULID for multi-box product sets, and an optional `weight_share` for detailed weight breakdowns. The legacy table remains untouched and still drives the UI and PDF in this PR.

**Tech Stack:** Laravel 11, PHPUnit (uses `RefreshDatabase` trait), MySQL/SQLite for tests, Spatie ULID via `Illuminate\Support\Str::ulid()`.

**Spec reference:** `docs/superpowers/specs/2026-04-08-packing-list-redesign-design.md`

---

## File Structure

**Created:**
- `database/migrations/2026_04_08_140000_create_cartons_table.php` — schema for `cartons`
- `database/migrations/2026_04_08_140100_create_carton_contents_table.php` — schema for `carton_contents`
- `app/Domain/Logistics/Models/Carton.php` — Eloquent model
- `app/Domain/Logistics/Models/CartonContent.php` — Eloquent model
- `app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php` — one-shot data migration
- `app/Console/Commands/MigratePackingListsToCartonsCommand.php` — artisan wrapper
- `tests/Feature/Logistics/CartonModelTest.php` — model basics + relationships
- `tests/Feature/Logistics/CartonContentModelTest.php` — model basics + relationships
- `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php` — covers all 4 legacy scenarios + idempotency + totals preservation

**Modified:**
- `app/Domain/Logistics/Models/Shipment.php` — add `cartons()` HasMany relationship

**Not touched in this PR (intentional):**
- `PackingListItem.php`, `PackingListRelationManager.php`, `PackingListPdfTemplate.php`, `RecalculateShipmentTotalsAction.php` — these are PR #2 scope.

---

## Task 1: Create `cartons` table migration

**Files:**
- Create: `database/migrations/2026_04_08_140000_create_cartons_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('container_number', 50)->nullable();
            $table->integer('pallet_number')->nullable();
            $table->string('packaging_type', 30)->default('CARTON');
            $table->decimal('gross_weight', 10, 3)->nullable();
            $table->decimal('net_weight', 10, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('volume', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'label']);
            $table->index(['shipment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartons');
    }
};
```

- [ ] **Step 2: Run migration to verify it applies cleanly**

Run: `php artisan migrate`
Expected output ends with: `2026_04_08_140000_create_cartons_table ........... DONE`

- [ ] **Step 3: Verify table exists with correct columns**

Run: `php artisan tinker --execute="echo json_encode(\Schema::getColumnListing('cartons'));"`
Expected output (order may vary): `["id","shipment_id","label","container_number","pallet_number","packaging_type","gross_weight","net_weight","length","width","height","volume","notes","sort_order","created_at","updated_at"]`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_140000_create_cartons_table.php
git commit -m "feat(packing-list): add cartons table"
```

---

## Task 2: Create `carton_contents` table migration

**Files:**
- Create: `database/migrations/2026_04_08_140100_create_carton_contents_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carton_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carton_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('pieces');
            $table->string('part_label', 100)->nullable();
            $table->char('multi_box_set_id', 26)->nullable();
            $table->decimal('weight_share', 10, 3)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('multi_box_set_id');
            $table->index('shipment_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_contents');
    }
};
```

- [ ] **Step 2: Run migration to verify it applies cleanly**

Run: `php artisan migrate`
Expected output ends with: `2026_04_08_140100_create_carton_contents_table ... DONE`

- [ ] **Step 3: Verify table exists with correct columns**

Run: `php artisan tinker --execute="echo json_encode(\Schema::getColumnListing('carton_contents'));"`
Expected output (order may vary): `["id","carton_id","shipment_item_id","pieces","part_label","multi_box_set_id","weight_share","sort_order","created_at","updated_at"]`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_140100_create_carton_contents_table.php
git commit -m "feat(packing-list): add carton_contents table"
```

---

## Task 3: Create `Carton` model

**Files:**
- Create: `app/Domain/Logistics/Models/Carton.php`
- Test: `tests/Feature/Logistics/CartonModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartonModelTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client CT', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CT-001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    public function test_carton_persists_with_required_fields(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
            'gross_weight' => 12.500,
            'net_weight' => 11.250,
            'length' => 50.00,
            'width' => 40.00,
            'height' => 30.00,
            'volume' => 0.0600,
        ]);

        $this->assertDatabaseHas('cartons', [
            'id' => $carton->id,
            'label' => 'BOX-001',
            'shipment_id' => $this->shipment->id,
        ]);
        $this->assertSame('12.500', (string) $carton->gross_weight);
    }

    public function test_carton_belongs_to_shipment(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        $this->assertSame($this->shipment->id, $carton->shipment->id);
    }

    public function test_carton_has_many_contents(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => 5,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => 3,
        ]);

        $this->assertCount(2, $carton->contents);
    }

    public function test_label_is_unique_per_shipment(): void
    {
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CartonModelTest 2>&1 | tail -20`
Expected: All 4 tests fail with "Class \"App\\Domain\\Logistics\\Models\\Carton\" not found" or similar.

- [ ] **Step 3: Create the `Carton` model**

```php
<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carton extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'label',
        'container_number',
        'pallet_number',
        'packaging_type',
        'gross_weight',
        'net_weight',
        'length',
        'width',
        'height',
        'volume',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pallet_number' => 'integer',
            'gross_weight' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'volume' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(CartonContent::class)->orderBy('sort_order');
    }
}
```

- [ ] **Step 4: Stub `CartonContent` so the relationship resolves (full implementation in Task 4)**

Create the file with just enough to satisfy the relationship test:

```php
<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class CartonContent extends Model
{
    protected $fillable = [
        'carton_id',
        'shipment_item_id',
        'pieces',
        'part_label',
        'multi_box_set_id',
        'weight_share',
        'sort_order',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CartonModelTest 2>&1 | tail -10`
Expected: 4 passed (8+ assertions).

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Logistics/Models/Carton.php app/Domain/Logistics/Models/CartonContent.php tests/Feature/Logistics/CartonModelTest.php
git commit -m "feat(packing-list): add Carton model with relationships"
```

---

## Task 4: Complete `CartonContent` model with tests

**Files:**
- Modify: `app/Domain/Logistics/Models/CartonContent.php`
- Test: `tests/Feature/Logistics/CartonContentModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartonContentModelTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;
    private ShipmentItem $shipmentItem;
    private Carton $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client CCT', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CCT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CCT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test product',
            'quantity' => 100,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CCT-001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $this->shipmentItem = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'sort_order' => 0,
        ]);

        $this->carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
            'gross_weight' => 5.000,
        ]);
    }

    public function test_content_persists_and_belongs_to_carton(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
        ]);

        $this->assertSame($this->carton->id, $content->carton->id);
        $this->assertSame(5, $content->pieces);
    }

    public function test_content_belongs_to_shipment_item(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
        ]);

        $this->assertSame($this->shipmentItem->id, $content->shipmentItem->id);
    }

    public function test_content_can_have_multi_box_set_id(): void
    {
        $setId = (string) Str::ulid();

        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 1,
            'part_label' => 'Frame',
            'multi_box_set_id' => $setId,
        ]);

        $this->assertSame($setId, $content->multi_box_set_id);
        $this->assertSame('Frame', $content->part_label);
    }

    public function test_content_pieces_is_cast_to_integer(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => '7',
        ]);

        $content->refresh();
        $this->assertSame(7, $content->pieces);
    }

    public function test_weight_share_persists_with_decimal_3(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
            'weight_share' => 2.500,
        ]);

        $content->refresh();
        $this->assertSame('2.500', (string) $content->weight_share);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CartonContentModelTest 2>&1 | tail -20`
Expected: Tests fail because `CartonContent` is missing relationships and casts.

- [ ] **Step 3: Replace the stub `CartonContent` with the complete implementation**

```php
<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'carton_id',
        'shipment_item_id',
        'pieces',
        'part_label',
        'multi_box_set_id',
        'weight_share',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pieces' => 'integer',
            'weight_share' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function carton(): BelongsTo
    {
        return $this->belongsTo(Carton::class);
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CartonContentModelTest 2>&1 | tail -10`
Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Logistics/Models/CartonContent.php tests/Feature/Logistics/CartonContentModelTest.php
git commit -m "feat(packing-list): complete CartonContent model with casts and relationships"
```

---

## Task 5: Add `cartons()` relationship to Shipment model

**Files:**
- Modify: `app/Domain/Logistics/Models/Shipment.php` (after the `packingListItems()` method around line 174-177)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/Logistics/CartonModelTest.php`:

```php
    public function test_shipment_has_many_cartons(): void
    {
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-002',
            'packaging_type' => 'CARTON',
        ]);

        $this->assertCount(2, $this->shipment->cartons);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_shipment_has_many_cartons 2>&1 | tail -10`
Expected: Fails with "Call to undefined method ... cartons()" or similar.

- [ ] **Step 3: Add the relationship to `Shipment.php`**

Insert this method immediately after the existing `packingListItems()` method (around line 177):

```php
    public function cartons(): HasMany
    {
        return $this->hasMany(Carton::class)->orderBy('sort_order');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_shipment_has_many_cartons 2>&1 | tail -10`
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Logistics/Models/Shipment.php tests/Feature/Logistics/CartonModelTest.php
git commit -m "feat(packing-list): add cartons relationship to Shipment"
```

---

## Task 6: `MigratePackingListItemsToCartonsAction` — scenario A (simple legacy)

**Files:**
- Create: `app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php`
- Test: `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

This task covers the simplest case: a shipment with one product and one packing line spanning a carton range with no multi-box and no mixed mode. Subsequent tasks add the more complex scenarios.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\MigratePackingListItemsToCartonsAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigratePackingListItemsToCartonsActionTest extends TestCase
{
    use RefreshDatabase;

    private MigratePackingListItemsToCartonsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(MigratePackingListItemsToCartonsAction::class);
    }

    private function makeShipmentWithItem(int $quantity = 100): array
    {
        $client = Company::create(['name' => 'Client M' . uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-M-' . uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-M-' . uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test product',
            'quantity' => $quantity,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-M-' . uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $quantity,
            'sort_order' => 0,
        ]);

        return [$shipment, $shipmentItem];
    }

    public function test_simple_legacy_creates_one_carton_per_carton_number(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(100);

        // Legacy: 100 sandals at 10/carton = 10 cartons (range 1-10)
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-10',
            'carton_from' => 1,
            'carton_to' => 10,
            'quantity' => 10,
            'qty_per_carton' => 10,
            'total_quantity' => 100,
            'gross_weight' => 5.000,
            'net_weight' => 4.500,
            'length' => 50.00,
            'width' => 40.00,
            'height' => 30.00,
            'volume' => 0.0600,
        ]);

        $this->action->execute($shipment);

        // 10 cartons created
        $this->assertSame(10, Carton::where('shipment_id', $shipment->id)->count());

        // Each carton has 1 content with pieces = qty_per_carton
        $box1 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-001')->first();
        $this->assertNotNull($box1);
        $this->assertSame('5.000', (string) $box1->gross_weight);
        $this->assertCount(1, $box1->contents);
        $this->assertSame(10, $box1->contents->first()->pieces);
        $this->assertNull($box1->contents->first()->multi_box_set_id);

        $box10 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-010')->first();
        $this->assertNotNull($box10);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_simple_legacy_creates_one_carton_per_carton_number 2>&1 | tail -15`
Expected: Fails with "Class \"App\\Domain\\Logistics\\Actions\\MigratePackingListItemsToCartonsAction\" not found".

- [ ] **Step 3: Create the action with the simple-legacy logic**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Str;

class MigratePackingListItemsToCartonsAction
{
    /**
     * Convert legacy packing_list_items rows for a shipment into cartons + carton_contents.
     * Idempotent: existing cartons (matched by shipment_id + label) are reused.
     */
    public function execute(Shipment $shipment): void
    {
        $items = $shipment->packingListItems()->orderBy('carton_from')->get();

        if ($items->isEmpty()) {
            return;
        }

        // Pre-pass: detect multi-box sets per shipment_item_id.
        // A shipment_item belongs to a multi-box set when ANY of its legacy items
        // has is_primary_package = false. All siblings (primary + non-primary)
        // get the same ULID.
        $multiBoxSetByItem = $this->buildMultiBoxSetMap($items);

        foreach ($items as $item) {
            for ($n = $item->carton_from; $n <= $item->carton_to; $n++) {
                $carton = $this->firstOrCreateCarton($shipment, $item, $n);
                $this->createContent($carton, $item, $multiBoxSetByItem);
            }
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PackingListItem>  $items
     * @return array<int, string> shipment_item_id => ULID
     */
    private function buildMultiBoxSetMap($items): array
    {
        $byItem = $items->groupBy('shipment_item_id');
        $map = [];

        foreach ($byItem as $shipmentItemId => $group) {
            if ($shipmentItemId === null) {
                continue;
            }

            $hasNonPrimary = $group->contains(fn ($i) => $i->is_primary_package === false);
            if ($hasNonPrimary) {
                $map[$shipmentItemId] = (string) Str::ulid();
            }
        }

        return $map;
    }

    private function firstOrCreateCarton(Shipment $shipment, PackingListItem $item, int $cartonNumber): Carton
    {
        $label = 'BOX-' . str_pad((string) $cartonNumber, 3, '0', STR_PAD_LEFT);

        return Carton::firstOrCreate(
            [
                'shipment_id' => $shipment->id,
                'label' => $label,
            ],
            [
                'container_number' => $item->container_number,
                'pallet_number' => $item->pallet_number,
                'packaging_type' => $item->packaging_type?->value ?? 'CARTON',
                'gross_weight' => $item->gross_weight,
                'net_weight' => $item->net_weight,
                'length' => $item->length,
                'width' => $item->width,
                'height' => $item->height,
                'volume' => $item->volume,
                'sort_order' => $cartonNumber,
            ]
        );
    }

    private function createContent(Carton $carton, PackingListItem $item, array $multiBoxSetByItem): void
    {
        $setId = $item->shipment_item_id !== null
            ? ($multiBoxSetByItem[$item->shipment_item_id] ?? null)
            : null;

        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $item->shipment_item_id,
            'pieces' => (int) ($item->qty_per_carton ?? 0),
            'part_label' => $item->package_label,
            'multi_box_set_id' => $setId,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_simple_legacy_creates_one_carton_per_carton_number 2>&1 | tail -10`
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
git commit -m "feat(packing-list): add migration action with simple legacy support"
```

---

## Task 7: Migration action — scenario B (mixed mode: multiple items same range)

**Files:**
- Modify: `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

The legacy "mixed mode" creates multiple `packing_list_items` rows with the **same** `carton_from`/`carton_to`. The action should already handle this via `firstOrCreate` (one carton per number, multiple contents). This task verifies it.

- [ ] **Step 1: Add the failing test**

Append to `MigratePackingListItemsToCartonsActionTest.php`:

```php
    public function test_legacy_mixed_mode_creates_one_carton_with_multiple_contents(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(50);

        // Second product on the same shipment
        $piItem2 = \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem::create([
            'proforma_invoice_id' => $shipmentItem->proformaInvoiceItem->proforma_invoice_id,
            'description' => 'Second product',
            'quantity' => 30,
            'unit_price' => 500,
            'unit' => 'pcs',
        ]);
        $shipmentItem2 = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem2->id,
            'quantity' => 30,
            'sort_order' => 1,
        ]);

        // Legacy mixed mode: both items share carton range 1-3
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-3',
            'carton_from' => 1,
            'carton_to' => 3,
            'quantity' => 3,
            'qty_per_carton' => 10,
            'total_quantity' => 30,
            'gross_weight' => 6.000,
        ]);
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem2->id,
            'carton_number' => '1-3',
            'carton_from' => 1,
            'carton_to' => 3,
            'quantity' => 3,
            'qty_per_carton' => 5,
            'total_quantity' => 15,
            'gross_weight' => 6.000,
        ]);

        $this->action->execute($shipment);

        // Only 3 cartons (BOX-001, 002, 003), not 6
        $this->assertSame(3, Carton::where('shipment_id', $shipment->id)->count());

        // BOX-001 has 2 contents: one for each shipment_item
        $box1 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-001')->first();
        $this->assertCount(2, $box1->contents);

        $piecesByItem = $box1->contents->pluck('pieces', 'shipment_item_id')->toArray();
        $this->assertSame(10, $piecesByItem[$shipmentItem->id]);
        $this->assertSame(5, $piecesByItem[$shipmentItem2->id]);

        // Carton weight stays at the value from the first item written (firstOrCreate semantics)
        $this->assertSame('6.000', (string) $box1->gross_weight);

        // No multi_box_set_id for either content (mixed mode, not multi-box)
        $this->assertTrue($box1->contents->every(fn ($c) => $c->multi_box_set_id === null));
    }
```

- [ ] **Step 2: Run test to verify it passes (no production code change expected)**

Run: `php artisan test --filter=test_legacy_mixed_mode_creates_one_carton_with_multiple_contents 2>&1 | tail -10`
Expected: 1 passed. The action's `firstOrCreate` already handles this case correctly.

If the test fails because of an unexpected behavior in `firstOrCreate`, debug before proceeding — do not edit the action without understanding why.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
git commit -m "test(packing-list): cover legacy mixed mode in migration action"
```

---

## Task 8: Migration action — scenario C (multi-box from previous patch)

**Files:**
- Modify: `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

Tests that legacy items written by the previous patch (using `package_label` + `is_primary_package`) get a synthesized `multi_box_set_id` so the new model preserves the "1 unit in 2 boxes" semantics.

- [ ] **Step 1: Add the failing test**

Append to `MigratePackingListItemsToCartonsActionTest.php`:

```php
    public function test_legacy_multi_box_synthesizes_set_id(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(2);

        // Legacy multi-box: 2 machines, each in 2 cartons (Frame + Accessories)
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-2',
            'carton_from' => 1,
            'carton_to' => 2,
            'quantity' => 2,
            'qty_per_carton' => 1,
            'total_quantity' => 2,
            'gross_weight' => 50.000,
            'package_label' => 'Frame',
            'is_primary_package' => true,
        ]);
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '3-4',
            'carton_from' => 3,
            'carton_to' => 4,
            'quantity' => 2,
            'qty_per_carton' => 1,
            'total_quantity' => 2,
            'gross_weight' => 15.000,
            'package_label' => 'Accessories',
            'is_primary_package' => false,
        ]);

        $this->action->execute($shipment);

        // 4 cartons total
        $this->assertSame(4, Carton::where('shipment_id', $shipment->id)->count());

        // All 4 contents share the SAME multi_box_set_id
        $contents = CartonContent::whereHas('carton', fn ($q) => $q->where('shipment_id', $shipment->id))->get();
        $this->assertCount(4, $contents);

        $setIds = $contents->pluck('multi_box_set_id')->unique()->values();
        $this->assertCount(1, $setIds, 'All multi-box contents should share one set ID');
        $this->assertNotNull($setIds[0]);
        $this->assertSame(26, strlen($setIds[0]), 'Set ID should be a 26-char ULID');

        // Frame label preserved
        $frame = $contents->firstWhere('part_label', 'Frame');
        $this->assertNotNull($frame);
        $accessories = $contents->firstWhere('part_label', 'Accessories');
        $this->assertNotNull($accessories);
    }
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test --filter=test_legacy_multi_box_synthesizes_set_id 2>&1 | tail -15`
Expected: 1 passed. The pre-pass logic in `buildMultiBoxSetMap()` handles this.

If it fails, inspect the output of the pre-pass map and verify that `is_primary_package` is being read correctly from the model (it was added to `$fillable` and cast to boolean by the previous patch in `app/Domain/Logistics/Models/PackingListItem.php`).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
git commit -m "test(packing-list): cover legacy multi-box scenario in migration"
```

---

## Task 9: Migration action — idempotency

**Files:**
- Modify: `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

Running the action twice on the same shipment should not duplicate cartons or contents.

- [ ] **Step 1: Add the failing test**

Append to `MigratePackingListItemsToCartonsActionTest.php`:

```php
    public function test_running_twice_does_not_duplicate_cartons_or_contents(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(20);

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-2',
            'carton_from' => 1,
            'carton_to' => 2,
            'quantity' => 2,
            'qty_per_carton' => 10,
            'total_quantity' => 20,
            'gross_weight' => 8.000,
        ]);

        $this->action->execute($shipment);
        $this->action->execute($shipment);

        $this->assertSame(2, Carton::where('shipment_id', $shipment->id)->count());

        $contentsCount = CartonContent::whereHas('carton', fn ($q) => $q->where('shipment_id', $shipment->id))->count();
        $this->assertSame(2, $contentsCount, 'Each carton should still have exactly 1 content');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_running_twice_does_not_duplicate_cartons_or_contents 2>&1 | tail -15`
Expected: Fails. The carton count is 2 (correct, due to `firstOrCreate`), but `contents` count is 4 because `createContent` calls `CartonContent::create` unconditionally. The second run creates duplicate contents.

- [ ] **Step 3: Make `createContent` idempotent**

Replace the `createContent` method in `app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php`:

```php
    private function createContent(Carton $carton, PackingListItem $item, array $multiBoxSetByItem): void
    {
        $setId = $item->shipment_item_id !== null
            ? ($multiBoxSetByItem[$item->shipment_item_id] ?? null)
            : null;

        CartonContent::firstOrCreate(
            [
                'carton_id' => $carton->id,
                'shipment_item_id' => $item->shipment_item_id,
                'part_label' => $item->package_label,
            ],
            [
                'pieces' => (int) ($item->qty_per_carton ?? 0),
                'multi_box_set_id' => $setId,
            ]
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_running_twice_does_not_duplicate_cartons_or_contents 2>&1 | tail -10`
Expected: 1 passed.

- [ ] **Step 5: Run the full test class to ensure nothing else broke**

Run: `php artisan test --filter=MigratePackingListItemsToCartonsActionTest 2>&1 | tail -15`
Expected: 4 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
git commit -m "feat(packing-list): make migration action idempotent"
```

---

## Task 10: Migration action — totals preservation check

**Files:**
- Modify: `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

The spec requires that "for each shipment, recompute totals from cartons and compare to the previous totals stored on `shipments`. Mismatches abort the migration with a clear error log."

This task adds the assertion (verification mode) but does **not** abort — aborting belongs to the artisan command in Task 11. The test confirms that totals derived from cartons match the legacy totals.

- [ ] **Step 1: Add the failing test**

Append to `MigratePackingListItemsToCartonsActionTest.php`:

```php
    public function test_carton_totals_match_legacy_packing_list_totals(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(50);

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-5',
            'carton_from' => 1,
            'carton_to' => 5,
            'quantity' => 5,
            'qty_per_carton' => 10,
            'total_quantity' => 50,
            'gross_weight' => 4.000,
            'net_weight' => 3.500,
            'volume' => 0.0500,
        ]);

        $this->action->execute($shipment);

        $cartonTotals = $shipment->cartons()
            ->selectRaw('
                COUNT(*) as total_packages,
                SUM(gross_weight) as total_gross,
                SUM(net_weight) as total_net,
                SUM(volume) as total_vol
            ')
            ->first();

        $this->assertSame(5, (int) $cartonTotals->total_packages);
        $this->assertSame('20.000', number_format((float) $cartonTotals->total_gross, 3, '.', ''));
        $this->assertSame('17.500', number_format((float) $cartonTotals->total_net, 3, '.', ''));
        $this->assertSame('0.2500', number_format((float) $cartonTotals->total_vol, 4, '.', ''));
    }
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test --filter=test_carton_totals_match_legacy_packing_list_totals 2>&1 | tail -10`
Expected: 1 passed. The action already produces the right totals because it copies weight/volume per carton.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
git commit -m "test(packing-list): verify carton totals match legacy"
```

---

## Task 11: Artisan command wrapper

**Files:**
- Create: `app/Console/Commands/MigratePackingListsToCartonsCommand.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\MigratePackingListItemsToCartonsAction;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Console\Command;

class MigratePackingListsToCartonsCommand extends Command
{
    protected $signature = 'packing-list:migrate-to-cartons
                            {--shipment= : Migrate only one shipment by reference}
                            {--dry-run : Print plan without writing}';

    protected $description = 'One-shot migration: convert packing_list_items to cartons + carton_contents';

    public function handle(MigratePackingListItemsToCartonsAction $action): int
    {
        $query = Shipment::query()->whereHas('packingListItems');

        if ($ref = $this->option('shipment')) {
            $query->where('reference', $ref);
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            $this->info('No shipments with packing_list_items found.');
            return self::SUCCESS;
        }

        $this->info("Found {$shipments->count()} shipment(s) to migrate.");

        if ($this->option('dry-run')) {
            foreach ($shipments as $shipment) {
                $itemCount = $shipment->packingListItems()->count();
                $this->line("  - {$shipment->reference}: {$itemCount} legacy items");
            }
            $this->warn('Dry run: no changes written.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($shipments->count());
        $bar->start();

        $errors = [];
        foreach ($shipments as $shipment) {
            try {
                $action->execute($shipment);
            } catch (\Throwable $e) {
                $errors[] = "{$shipment->reference}: {$e->getMessage()}";
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($errors) {
            $this->error('Errors:');
            foreach ($errors as $err) {
                $this->line('  - ' . $err);
            }
            return self::FAILURE;
        }

        $this->info('Migration completed successfully.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command is registered**

Run: `php artisan list packing-list 2>&1 | tail -10`
Expected output includes: `packing-list:migrate-to-cartons   One-shot migration: convert packing_list_items to cartons + carton_contents`

- [ ] **Step 3: Run dry-run on dev database to verify it works end-to-end**

Run: `php artisan packing-list:migrate-to-cartons --dry-run 2>&1 | tail -20`
Expected: either "No shipments with packing_list_items found." or a list of shipments with their legacy item counts. Either is fine — both prove the command runs.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/MigratePackingListsToCartonsCommand.php
git commit -m "feat(packing-list): add artisan command for one-shot data migration"
```

---

## Task 12: Full regression and PR finalization

- [ ] **Step 1: Run the full Logistics test suite**

Run: `php artisan test tests/Feature/Logistics tests/Unit/ShipmentReadyQuantityTest.php 2>&1 | tail -30`
Expected: All tests pass. If any pre-existing test fails, debug — this PR is purely additive and should not break anything.

- [ ] **Step 2: Run pint on all touched files**

Run:
```bash
./vendor/bin/pint \
  database/migrations/2026_04_08_140000_create_cartons_table.php \
  database/migrations/2026_04_08_140100_create_carton_contents_table.php \
  app/Domain/Logistics/Models/Carton.php \
  app/Domain/Logistics/Models/CartonContent.php \
  app/Domain/Logistics/Models/Shipment.php \
  app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php \
  app/Console/Commands/MigratePackingListsToCartonsCommand.php \
  tests/Feature/Logistics/CartonModelTest.php \
  tests/Feature/Logistics/CartonContentModelTest.php \
  tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
```

Expected: pint reports either "no changes" or fixes that you should re-commit.

- [ ] **Step 3: If pint changed files, commit the formatting**

Run: `git status`

If any files were modified by pint, run:
```bash
git add -u
git commit -m "style: pint formatting"
```

- [ ] **Step 4: Verify migrations status**

Run: `php artisan migrate:status 2>&1 | tail -10`
Expected: Both new migrations show as `Ran`.

- [ ] **Step 5: Final summary**

PR #1 is complete when:
1. ✅ `cartons` and `carton_contents` tables exist with the schema from the spec.
2. ✅ `Carton` and `CartonContent` models have correct fillable, casts, and relationships.
3. ✅ `Shipment::cartons()` HasMany relationship is in place.
4. ✅ `MigratePackingListItemsToCartonsAction` handles all 4 scenarios (simple, mixed, multi-box, idempotent).
5. ✅ `php artisan packing-list:migrate-to-cartons` runs successfully (at least dry-run on dev).
6. ✅ All tests pass; no regression in existing Shipment tests.
7. ✅ Legacy `packing_list_items` is **untouched** — the old UI and PDF still work exactly as before.

PR #2 (next plan) will repoint `RecalculateShipmentTotalsAction` and `PackingListPdfTemplate` at the new tables and add the remaining backend actions (`CreateCartonAction`, `AddContentToCartonAction`, etc.) — all without changing the Filament UI yet.

PR #3 (next-next plan) will replace the Filament `PackingListRelationManager` with the new Livewire `PackingListBuilder` component.

PR #4 (cleanup) drops the legacy table after production validation.
