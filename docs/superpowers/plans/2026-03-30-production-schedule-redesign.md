# Production Schedule Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the read-only supplier production schedule view with a full Livewire grid (supplier creates agenda, client approves, admin enters actuals), and add a component/parts inventory per schedule.

**Architecture:** Four new Livewire components embedded in Filament pages via `ViewEntry` wrapper blades. New `status` field on `production_schedules` drives what each portal can do. New `production_schedule_components` table holds per-product parts readiness. Existing `UpdatePaymentScheduleFromProductionAction` is called after admin saves actuals.

**Tech Stack:** Laravel 12, Livewire 3, Filament v3, Pest for tests.

---

## File Map

### Create
| File | Purpose |
|------|---------|
| `database/migrations/2026_03_30_110000_add_status_fields_to_production_schedules.php` | Status + approval columns |
| `database/migrations/2026_03_30_120000_create_production_schedule_components_table.php` | Parts inventory table |
| `app/Domain/Planning/Enums/ProductionScheduleStatus.php` | Status enum with colors/labels |
| `app/Domain/Planning/Enums/ComponentStatus.php` | Parts status enum |
| `app/Domain/Planning/Models/ProductionScheduleComponent.php` | Parts model |
| `database/factories/ProductionScheduleComponentFactory.php` | Test factory |
| `app/Livewire/SupplierPortal/ProductionScheduleGrid.php` | Livewire grid — supplier edits |
| `resources/views/livewire/supplier-portal/production-schedule-grid.blade.php` | Grid blade |
| `resources/views/filament/supplier-portal/production-schedule-grid-entry.blade.php` | Filament ViewEntry wrapper |
| `app/Livewire/SupplierPortal/ComponentInventoryPanel.php` | Livewire parts panel |
| `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php` | Parts panel blade |
| `resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php` | Filament ViewEntry wrapper |
| `app/Livewire/Portal/ScheduleApprovalWidget.php` | Livewire approval — client portal |
| `resources/views/livewire/portal/schedule-approval-widget.blade.php` | Approval blade |
| `app/Livewire/Admin/ProductionActualsGrid.php` | Livewire actuals — admin |
| `resources/views/livewire/admin/production-actuals-grid.blade.php` | Actuals blade |
| `resources/views/filament/production-schedule/actuals-grid-entry.blade.php` | Filament ViewEntry wrapper |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/CreateProductionSchedule.php` | Supplier creates schedule |
| `tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php` | Grid tests |
| `tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php` | Parts tests |
| `tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php` | Approval tests |
| `tests/Feature/Livewire/Admin/ProductionActualsGridTest.php` | Actuals tests |

### Modify
| File | Change |
|------|--------|
| `app/Domain/Planning/Models/ProductionSchedule.php` | Add fillable, casts, relationships, helper methods |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php` | Add status column, enable create, update infolist |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php` | Add Livewire ViewEntry sections, remove relation manager |
| `app/Filament/Resources/ProductionSchedules/Pages/ViewProductionSchedule.php` | Add actuals grid section |
| `resources/views/portal/infolists/pi-production-progress.blade.php` | Add approval widget per schedule |

---

## Task 0: Test Factories (prerequisite for all tests)

**Files:**
- Create: `database/factories/ProductionScheduleFactory.php`
- Create: `database/factories/ProductionScheduleEntryFactory.php`
- Create: `database/factories/ProformaInvoiceFactory.php`
- Create: `database/factories/ProformaInvoiceItemFactory.php`

> ⚠️ No factories exist for these models. Tests in Tasks 4-7 will fail without them.

- [ ] **Step 1: Write ProductionScheduleFactory**

```php
<?php
// database/factories/ProductionScheduleFactory.php

namespace Database\Factories;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleFactory extends Factory
{
    protected $model = ProductionSchedule::class;

    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'purchase_order_id'   => null,
            'supplier_company_id' => \App\Domain\CRM\Models\Company::factory(),
            'reference'           => 'PS-' . $this->faker->unique()->numerify('####'),
            'status'              => ProductionScheduleStatus::Draft,
            'received_date'       => null,
            'version'             => 1,
            'notes'               => null,
            'created_by'          => null,
        ];
    }
}
```

- [ ] **Step 2: Write ProductionScheduleEntryFactory**

```php
<?php
// database/factories/ProductionScheduleEntryFactory.php

namespace Database\Factories;

use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleEntryFactory extends Factory
{
    protected $model = ProductionScheduleEntry::class;

    public function definition(): array
    {
        return [
            'production_schedule_id'   => ProductionSchedule::factory(),
            'proforma_invoice_item_id' => ProformaInvoiceItem::factory(),
            'purchase_order_item_id'   => null,
            'production_date'          => $this->faker->dateTimeBetween('+1 week', '+8 weeks'),
            'quantity'                 => $this->faker->numberBetween(50, 300),
            'actual_quantity'          => null,
        ];
    }
}
```

- [ ] **Step 3: Check if ProformaInvoice and ProformaInvoiceItem models use HasFactory**

```bash
grep -n "HasFactory\|use Factory" \
  app/Domain/ProformaInvoices/Models/ProformaInvoice.php \
  app/Domain/ProformaInvoices/Models/ProformaInvoiceItem.php
```

If `HasFactory` is missing from either model, add the trait:
```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
// inside the class:
use HasFactory;
```

Also add `HasFactory` to `ProductionSchedule` and `ProductionScheduleEntry` models if missing.

- [ ] **Step 4: Write ProformaInvoiceFactory (minimal — for test isolation)**

```php
<?php
// database/factories/ProformaInvoiceFactory.php

namespace Database\Factories;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceFactory extends Factory
{
    protected $model = ProformaInvoice::class;

    public function definition(): array
    {
        return [
            'company_id'    => \App\Domain\CRM\Models\Company::factory(),
            'reference'     => 'PI-' . $this->faker->unique()->numerify('####'),
            'status'        => 'draft',
            'currency_code' => 'USD',
        ];
    }
}
```

> **Note:** Run `php artisan tinker --execute="schema()->getColumnListing('proforma_invoices')"` first to confirm required columns, and adjust the factory to match any `NOT NULL` columns.

- [ ] **Step 5: Write ProformaInvoiceItemFactory**

```php
<?php
// database/factories/ProformaInvoiceItemFactory.php

namespace Database\Factories;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceItemFactory extends Factory
{
    protected $model = ProformaInvoiceItem::class;

    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'product_id'          => \App\Domain\Catalog\Models\Product::factory(),
            'description'         => $this->faker->words(3, true),
            'quantity'            => $this->faker->numberBetween(100, 500),
            'unit_price'          => $this->faker->randomFloat(2, 50, 500),
        ];
    }
}
```

> **Note:** Adjust columns to match actual `proforma_invoice_items` schema. Run `php artisan tinker --execute="schema()->getColumnListing('proforma_invoice_items')"` to verify.

- [ ] **Step 6: Run a smoke test to confirm factories work**

```bash
php artisan test --filter "it saves entry when updateQuantity"
```

Expected: the test runs (may fail on logic, not on factory instantiation).

- [ ] **Step 7: Commit**

```bash
git add database/factories/ProductionScheduleFactory.php \
        database/factories/ProductionScheduleEntryFactory.php \
        database/factories/ProformaInvoiceFactory.php \
        database/factories/ProformaInvoiceItemFactory.php
git commit -m "feat: add test factories for ProductionSchedule, ProformaInvoice, and related models"
```

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/2026_03_30_110000_add_status_fields_to_production_schedules.php`
- Create: `database/migrations/2026_03_30_120000_create_production_schedule_components_table.php`

- [ ] **Step 1: Write migration for status fields**

```php
<?php
// database/migrations/2026_03_30_110000_add_status_fields_to_production_schedules.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->after('notes');
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_notes')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->dropColumn(['status', 'submitted_at', 'approved_by', 'approved_at', 'approval_notes']);
        });
    }
};
```

- [ ] **Step 2: Write migration for components table**

```php
<?php
// database/migrations/2026_03_30_120000_create_production_schedule_components_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_schedule_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')
                ->constrained()->cascadeOnDelete();
            $table->string('component_name')->nullable();
            $table->string('status', 30)->default('at_supplier');
            $table->string('supplier_name')->nullable();
            $table->date('eta')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_schedule_id', 'proforma_invoice_item_id'], 'ps_components_schedule_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_schedule_components');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate
```

Expected: `2026_03_30_110000` and `2026_03_30_120000` run without errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_03_30_110000_add_status_fields_to_production_schedules.php \
        database/migrations/2026_03_30_120000_create_production_schedule_components_table.php
git commit -m "feat: add status fields to production_schedules and create production_schedule_components table"
```

---

## Task 2: Enums

**Files:**
- Create: `app/Domain/Planning/Enums/ProductionScheduleStatus.php`
- Create: `app/Domain/Planning/Enums/ComponentStatus.php`

- [ ] **Step 1: Write ProductionScheduleStatus**

```php
<?php
// app/Domain/Planning/Enums/ProductionScheduleStatus.php

namespace App\Domain\Planning\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProductionScheduleStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft            = 'draft';
    case PendingApproval  = 'pending_approval';
    case Approved         = 'approved';
    case Rejected         = 'rejected';
    case Completed        = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
            self::Completed       => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft           => 'gray',
            self::PendingApproval => 'warning',
            self::Approved        => 'success',
            self::Rejected        => 'danger',
            self::Completed       => 'primary',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft           => 'heroicon-o-pencil-square',
            self::PendingApproval => 'heroicon-o-clock',
            self::Approved        => 'heroicon-o-check-circle',
            self::Rejected        => 'heroicon-o-x-circle',
            self::Completed       => 'heroicon-o-check-badge',
        };
    }

    public function canBeEditedBySupplier(): bool
    {
        return in_array($this, [self::Draft, self::Rejected]);
    }
}
```

- [ ] **Step 2: Write ComponentStatus**

```php
<?php
// app/Domain/Planning/Enums/ComponentStatus.php

namespace App\Domain\Planning\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComponentStatus: string implements HasLabel, HasColor
{
    case AtFactory  = 'at_factory';
    case InTransit  = 'in_transit';
    case AtSupplier = 'at_supplier';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AtFactory  => 'At Factory',
            self::InTransit  => 'In Transit',
            self::AtSupplier => 'At Supplier',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AtFactory  => 'success',
            self::InTransit  => 'warning',
            self::AtSupplier => 'danger',
        };
    }

    public function isRisk(): bool
    {
        return $this !== self::AtFactory;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Planning/Enums/ProductionScheduleStatus.php \
        app/Domain/Planning/Enums/ComponentStatus.php
git commit -m "feat: add ProductionScheduleStatus and ComponentStatus enums"
```

---

## Task 3: Domain Models

**Files:**
- Create: `app/Domain/Planning/Models/ProductionScheduleComponent.php`
- Create: `database/factories/ProductionScheduleComponentFactory.php`
- Modify: `app/Domain/Planning/Models/ProductionSchedule.php`

- [ ] **Step 1: Write ProductionScheduleComponent model**

```php
<?php
// app/Domain/Planning/Models/ProductionScheduleComponent.php

namespace App\Domain\Planning\Models;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScheduleComponent extends Model
{
    protected $fillable = [
        'production_schedule_id',
        'proforma_invoice_item_id',
        'component_name',
        'status',
        'supplier_name',
        'eta',
        'notes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ComponentStatus::class,
            'eta'    => 'date',
        ];
    }

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Returns true if this component may delay production at the given date */
    public function isRiskForDate(\Carbon\Carbon $date): bool
    {
        if (!$this->status->isRisk()) {
            return false;
        }

        if ($this->eta === null) {
            return true; // unknown ETA is always a risk
        }

        return $this->eta->gt($date);
    }
}
```

- [ ] **Step 2: Write factory**

```php
<?php
// database/factories/ProductionScheduleComponentFactory.php

namespace Database\Factories;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleComponentFactory extends Factory
{
    protected $model = ProductionScheduleComponent::class;

    public function definition(): array
    {
        return [
            'production_schedule_id'   => ProductionSchedule::factory(),
            'proforma_invoice_item_id' => ProformaInvoiceItem::factory(),
            'component_name'           => null,
            'status'                   => ComponentStatus::AtSupplier,
            'supplier_name'            => $this->faker->company(),
            'eta'                      => $this->faker->dateTimeBetween('+1 week', '+6 weeks'),
            'notes'                    => null,
            'updated_by'               => null,
        ];
    }

    public function atFactory(): static
    {
        return $this->state(['status' => ComponentStatus::AtFactory, 'eta' => null]);
    }

    public function inTransit(): static
    {
        return $this->state(['status' => ComponentStatus::InTransit]);
    }

    public function named(string $name): static
    {
        return $this->state(['component_name' => $name]);
    }
}
```

- [ ] **Step 3: Update ProductionSchedule model**

Add to `$fillable`:
```php
'status',
'submitted_at',
'approved_by',
'approved_at',
'approval_notes',
```

Add to `casts()`:
```php
'status'       => \App\Domain\Planning\Enums\ProductionScheduleStatus::class,
'submitted_at' => 'datetime',
'approved_at'  => 'datetime',
```

Add after the `entries()` relationship:
```php
public function components(): HasMany
{
    return $this->hasMany(ProductionScheduleComponent::class);
}

public function approver(): BelongsTo
{
    return $this->belongsTo(User::class, 'approved_by');
}
```

Add helper methods after the existing accessors:
```php
/**
 * Returns item IDs whose components have a risk ETA for each production date.
 * Shape: ['item-123' => ['2025-04-15', '2025-04-22'], ...]
 */
public function componentRiskDates(): array
{
    $risk = [];

    $this->components->each(function (ProductionScheduleComponent $component) use (&$risk) {
        $key = 'item-' . $component->proforma_invoice_item_id;

        foreach ($this->entries as $entry) {
            if ($entry->proforma_invoice_item_id !== $component->proforma_invoice_item_id) {
                continue;
            }
            if ($component->isRiskForDate($entry->production_date)) {
                $risk[$key][] = $entry->production_date->format('Y-m-d');
            }
        }
    });

    // Deduplicate dates
    foreach ($risk as $key => $dates) {
        $risk[$key] = array_values(array_unique($dates));
    }

    return $risk;
}

public function hasComponentRisk(): bool
{
    return !empty($this->componentRiskDates());
}
```

- [ ] **Step 4: Run tests to verify models load**

```bash
php artisan test --filter ProductionSchedule
```

Expected: existing tests pass (no breakage from model additions).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Planning/Models/ProductionScheduleComponent.php \
        app/Domain/Planning/Models/ProductionSchedule.php \
        database/factories/ProductionScheduleComponentFactory.php
git commit -m "feat: add ProductionScheduleComponent model and update ProductionSchedule with status and components"
```

---

## Task 4: ProductionScheduleGrid Livewire Component (Supplier)

**Files:**
- Create: `app/Livewire/SupplierPortal/ProductionScheduleGrid.php`
- Create: `resources/views/livewire/supplier-portal/production-schedule-grid.blade.php`
- Create: `resources/views/filament/supplier-portal/production-schedule-grid-entry.blade.php`
- Create: `tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ProductionScheduleGrid;
use Livewire\Livewire;

beforeEach(function () {
    $this->supplier = \App\Models\User::factory()->create();
    $this->actingAs($this->supplier);
});

it('renders items and dates from existing entries', function () {
    $pi = ProformaInvoice::factory()->has(
        ProformaInvoiceItem::factory()->count(2), 'items'
    )->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);
    $item = $pi->items->first();
    ProductionScheduleEntry::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
        'production_date'          => '2025-04-10',
        'quantity'                 => 100,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->assertSee('2025-04-10')
        ->assertSet('dates', ['2025-04-10']);
});

it('saves entry when updateQuantity is called', function () {
    $pi     = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item   = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->call('updateQuantity', $item->id, '2025-04-10', '150');

    expect(
        ProductionScheduleEntry::where([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $item->id,
            'production_date'          => '2025-04-10',
            'quantity'                 => 150,
        ])->exists()
    )->toBeTrue();
});

it('adds a new date column', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->set('newDateInput', '2025-04-17')
        ->call('addDate')
        ->assertSet('dates', ['2025-04-17']);
});

it('removes a date and deletes its entries', function () {
    $pi   = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);
    ProductionScheduleEntry::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
        'production_date'          => '2025-04-10',
        'quantity'                 => 100,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->call('removeDate', '2025-04-10')
        ->assertSet('dates', []);

    expect(ProductionScheduleEntry::where('production_schedule_id', $schedule->id)->count())->toBe(0);
});

it('transitions to pending_approval on submit when quantities are sufficient', function () {
    $pi   = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory()->state(['quantity' => 100]), 'items')->create();
    $item = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);
    ProductionScheduleEntry::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
        'production_date'          => '2025-04-10',
        'quantity'                 => 100,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->call('submit');

    expect($schedule->fresh()->status)->toBe(ProductionScheduleStatus::PendingApproval);
});

it('does not allow editing when status is not draft or rejected', function () {
    $pi       = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item     = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Approved,
    ]);

    Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
        ->call('updateQuantity', $item->id, '2025-04-10', '999');

    expect(ProductionScheduleEntry::where('production_schedule_id', $schedule->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php
```

Expected: FAIL — `App\Livewire\SupplierPortal\ProductionScheduleGrid` not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php
// app/Livewire/SupplierPortal/ProductionScheduleGrid.php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ProductionScheduleGrid extends Component
{
    public ProductionSchedule $schedule;

    /** @var array<string, array<string, int>> ['item-{id}']['YYYY-MM-DD'] = quantity */
    public array $quantities = [];

    /** @var string[] sorted YYYY-MM-DD dates */
    public array $dates = [];

    /** @var array<int, array{id: int, name: string, sku: string, pi_quantity: int}> */
    public array $items = [];

    public bool $showAddDate = false;
    public ?string $newDateInput = null;

    /** @var array<string, string[]> ['item-{id}'] = ['YYYY-MM-DD', ...] */
    public array $riskDates = [];

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->schedule->load(['proformaInvoice.items.product', 'entries', 'components']);

        $this->items = $this->schedule->proformaInvoice->items
            ->map(fn ($item) => [
                'id'          => $item->id,
                'name'        => $item->product?->name ?? $item->description ?? '—',
                'sku'         => $item->product?->sku ?? '',
                'pi_quantity' => $item->quantity,
            ])
            ->values()
            ->toArray();

        $this->quantities = [];
        $dateSet = [];

        foreach ($this->schedule->entries as $entry) {
            $dateKey = $entry->production_date->format('Y-m-d');
            $itemKey = 'item-' . $entry->proforma_invoice_item_id;
            $this->quantities[$itemKey][$dateKey] = $entry->quantity;
            $dateSet[$dateKey] = true;
        }

        $this->dates = array_keys($dateSet);
        sort($this->dates);

        $this->riskDates = $this->schedule->componentRiskDates();
    }

    public function canEdit(): bool
    {
        return $this->schedule->status->canBeEditedBySupplier();
    }

    public function updateQuantity(int $itemId, string $date, ?string $value): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $quantity = ($value !== null && $value !== '') ? max(0, (int) $value) : 0;
        $itemKey  = 'item-' . $itemId;

        $this->quantities[$itemKey][$date] = $quantity;

        if ($quantity > 0) {
            ProductionScheduleEntry::updateOrCreate(
                [
                    'production_schedule_id'   => $this->schedule->id,
                    'proforma_invoice_item_id' => $itemId,
                    'production_date'          => $date,
                ],
                ['quantity' => $quantity]
            );
        } else {
            ProductionScheduleEntry::where([
                'production_schedule_id'   => $this->schedule->id,
                'proforma_invoice_item_id' => $itemId,
                'production_date'          => $date,
            ])->delete();
        }
    }

    public function addDate(): void
    {
        if (!$this->canEdit() || !$this->newDateInput) {
            $this->showAddDate = false;
            return;
        }

        if (!in_array($this->newDateInput, $this->dates)) {
            $this->dates[] = $this->newDateInput;
            sort($this->dates);
        }

        $this->newDateInput = null;
        $this->showAddDate  = false;
    }

    public function removeDate(string $date): void
    {
        if (!$this->canEdit()) {
            return;
        }

        ProductionScheduleEntry::where('production_schedule_id', $this->schedule->id)
            ->whereDate('production_date', $date)
            ->delete();

        $this->dates = array_values(array_filter($this->dates, fn ($d) => $d !== $date));

        foreach ($this->items as $item) {
            unset($this->quantities['item-' . $item['id']][$date]);
        }
    }

    public function submit(): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $errors = [];
        foreach ($this->items as $item) {
            $total = array_sum($this->quantities['item-' . $item['id']] ?? []);
            if ($total < $item['pi_quantity']) {
                $errors[] = "{$item['name']}: planned {$total} / PI qty {$item['pi_quantity']}";
            }
        }

        if (!empty($errors)) {
            Notification::make()
                ->danger()
                ->title('Validation failed — quantities insufficient')
                ->body(implode("\n", $errors))
                ->send();
            return;
        }

        $this->schedule->update([
            'status'       => ProductionScheduleStatus::PendingApproval,
            'submitted_at' => now(),
        ]);
        $this->schedule->refresh();

        Notification::make()->success()->title('Schedule submitted for approval')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function totalsPerDate(): array
    {
        $totals = [];
        foreach ($this->dates as $date) {
            $totals[$date] = 0;
            foreach ($this->items as $item) {
                $totals[$date] += $this->quantities['item-' . $item['id']][$date] ?? 0;
            }
        }
        return $totals;
    }

    public function totalsPerItem(): array
    {
        $totals = [];
        foreach ($this->items as $item) {
            $key = 'item-' . $item['id'];
            $totals[$key] = array_sum($this->quantities[$key] ?? []);
        }
        return $totals;
    }

    public function render(): View
    {
        return view('livewire.supplier-portal.production-schedule-grid', [
            'totalsPerDate' => $this->totalsPerDate(),
            'totalsPerItem' => $this->totalsPerItem(),
        ]);
    }
}
```

- [ ] **Step 4: Write the grid Blade view**

```blade
{{-- resources/views/livewire/supplier-portal/production-schedule-grid.blade.php --}}
<div wire:key="ps-grid-{{ $schedule->id }}">
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 rounded-t-lg">
        <div class="flex items-center gap-3">
            <span class="font-bold text-gray-900 dark:text-white">{{ $schedule->reference }}</span>
            <x-filament::badge :color="$schedule->status->getColor()">
                {{ $schedule->status->getLabel() }}
            </x-filament::badge>
            <span class="text-sm text-gray-500">
                {{ $schedule->proformaInvoice->reference }}
            </span>
        </div>
        @if($this->canEdit())
            <div class="flex items-center gap-2">
                <x-filament::button
                    size="sm"
                    color="gray"
                    wire:click="$set('showAddDate', true)"
                    icon="heroicon-o-plus">
                    Add Date
                </x-filament::button>
                <x-filament::button
                    size="sm"
                    color="primary"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-paper-airplane">
                    Submit for Approval
                </x-filament::button>
            </div>
        @endif
    </div>

    {{-- Add Date Input (inline) --}}
    @if($showAddDate)
        <div class="flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-white/10">
            <input type="date" wire:model="newDateInput"
                   class="text-sm border border-gray-300 dark:border-white/20 rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white">
            <x-filament::button size="xs" wire:click="addDate">Add</x-filament::button>
            <x-filament::button size="xs" color="gray" wire:click="$set('showAddDate', false)">Cancel</x-filament::button>
        </div>
    @endif

    {{-- Grid --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                <tr>
                    <th class="px-4 py-2.5 min-w-[160px]">Product</th>
                    <th class="px-3 py-2.5 text-center">PI Qty</th>
                    <th class="px-3 py-2.5 text-center">Balance</th>
                    @foreach($dates as $date)
                        <th class="px-3 py-2.5 text-center min-w-[100px]">
                            <div>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
                            <div class="font-normal text-gray-400">{{ \Carbon\Carbon::parse($date)->format('D') }}</div>
                            @if($this->canEdit())
                                <button wire:click="removeDate('{{ $date }}')"
                                        class="text-red-400 hover:text-red-600 text-xs mt-0.5">✕</button>
                            @endif
                        </th>
                    @endforeach
                    <th class="px-3 py-2.5 text-center">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach($items as $item)
                    @php
                        $itemKey  = 'item-' . $item['id'];
                        $total    = $totalsPerItem[$itemKey] ?? 0;
                        $balance  = $total - $item['pi_quantity'];
                    @endphp
                    <tr class="text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ $item['name'] }}</div>
                            @if($item['sku'])
                                <div class="text-xs text-gray-400">{{ $item['sku'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($item['pi_quantity']) }}</td>
                        <td class="px-3 py-2.5 text-center font-semibold {{ $balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $balance >= 0 ? '+' : '' }}{{ number_format($balance) }}
                        </td>
                        @foreach($dates as $date)
                            @php
                                $qty     = $quantities[$itemKey][$date] ?? null;
                                $isRisk  = in_array($date, $riskDates[$itemKey] ?? []);
                            @endphp
                            <td class="px-2 py-1.5 text-center">
                                @if($this->canEdit())
                                    <input
                                        type="number"
                                        min="0"
                                        value="{{ $qty ?? '' }}"
                                        placeholder="—"
                                        wire:change="updateQuantity({{ $item['id'] }}, '{{ $date }}', $event.target.value)"
                                        class="w-20 text-center text-sm border {{ $isRisk ? 'border-amber-400' : 'border-gray-300 dark:border-white/20' }} rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500"
                                    >
                                @else
                                    <span class="{{ !$qty ? 'text-gray-400' : '' }}">{{ $qty ? number_format($qty) : '—' }}</span>
                                @endif
                                @if($isRisk)
                                    <div class="text-xs text-amber-500 mt-0.5">⚠️ parts</div>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-3 py-2.5 text-center font-bold {{ $balance >= 0 ? 'text-primary-600 dark:text-primary-400' : 'text-red-600' }}">
                            {{ number_format($total) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if(count($dates) > 0)
                <tfoot>
                    <tr class="bg-gray-50 dark:bg-white/5 border-t-2 border-gray-200 dark:border-white/20 text-xs font-semibold text-gray-600 dark:text-gray-400">
                        <td colspan="3" class="px-4 py-2">TOTAL PER DATE</td>
                        @foreach($dates as $date)
                            <td class="px-3 py-2 text-center">{{ number_format($totalsPerDate[$date] ?? 0) }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-center">{{ number_format(array_sum($totalsPerDate)) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if(empty($dates) && $this->canEdit())
        <div class="px-4 py-8 text-center text-gray-400 text-sm">
            No production dates yet. Click <strong>Add Date</strong> to start building the schedule.
        </div>
    @endif
</div>
```

- [ ] **Step 5: Write the Filament ViewEntry wrapper blade**

```blade
{{-- resources/views/filament/supplier-portal/production-schedule-grid-entry.blade.php --}}
@php $record = $getRecord(); @endphp
<livewire:supplier-portal.production-schedule-grid
    :schedule="$record"
    :key="'ps-grid-' . $record->id"
/>
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php
```

Expected: all 5 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/SupplierPortal/ProductionScheduleGrid.php \
        resources/views/livewire/supplier-portal/production-schedule-grid.blade.php \
        resources/views/filament/supplier-portal/production-schedule-grid-entry.blade.php \
        tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php
git commit -m "feat: add ProductionScheduleGrid Livewire component for supplier portal"
```

---

## Task 5: ComponentInventoryPanel Livewire Component (Supplier)

**Files:**
- Create: `app/Livewire/SupplierPortal/ComponentInventoryPanel.php`
- Create: `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php`
- Create: `resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php`
- Create: `tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ComponentInventoryPanel;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(\App\Models\User::factory()->create()));

it('saves a product-level component status', function () {
    $pi       = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item     = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
        ->call('saveComponent', $item->id, null, 'at_factory', null, null);

    expect(
        ProductionScheduleComponent::where([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $item->id,
            'component_name'           => null,
            'status'                   => ComponentStatus::AtFactory->value,
        ])->exists()
    )->toBeTrue();
});

it('saves a named sub-component', function () {
    $pi       = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item     = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
        ->call('saveComponent', $item->id, 'LCD Panel', 'in_transit', 'Supplier Corp', '2025-04-20');

    expect(
        ProductionScheduleComponent::where([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $item->id,
            'component_name'           => 'LCD Panel',
            'status'                   => ComponentStatus::InTransit->value,
        ])->exists()
    )->toBeTrue();
});

it('deletes a component', function () {
    $pi       = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item     = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);
    $component = ProductionScheduleComponent::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
    ]);

    Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
        ->call('deleteComponent', $component->id);

    expect(ProductionScheduleComponent::find($component->id))->toBeNull();
});

it('does not allow editing when schedule is approved', function () {
    $pi       = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item     = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Approved,
    ]);

    Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
        ->call('saveComponent', $item->id, null, 'at_factory', null, null);

    expect(ProductionScheduleComponent::where('production_schedule_id', $schedule->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php artisan test tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php
// app/Livewire/SupplierPortal/ComponentInventoryPanel.php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use Illuminate\View\View;
use Livewire\Component;

class ComponentInventoryPanel extends Component
{
    public ProductionSchedule $schedule;

    /** @var int[] PI item IDs that are expanded to show sub-components */
    public array $expandedItems = [];

    /** @var bool whether the panel itself is expanded */
    public bool $isExpanded = true;

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
    }

    public function canEdit(): bool
    {
        return $this->schedule->status->canBeEditedBySupplier();
    }

    public function toggleExpand(int $itemId): void
    {
        if (in_array($itemId, $this->expandedItems)) {
            $this->expandedItems = array_values(
                array_filter($this->expandedItems, fn ($id) => $id !== $itemId)
            );
        } else {
            $this->expandedItems[] = $itemId;
        }
    }

    /**
     * Saves or updates a component. component_name = null means product-level status.
     */
    public function saveComponent(
        int $itemId,
        ?string $componentName,
        string $status,
        ?string $supplierName,
        ?string $eta
    ): void {
        if (!$this->canEdit()) {
            return;
        }

        ProductionScheduleComponent::updateOrCreate(
            [
                'production_schedule_id'   => $this->schedule->id,
                'proforma_invoice_item_id' => $itemId,
                'component_name'           => $componentName,
            ],
            [
                'status'        => ComponentStatus::from($status),
                'supplier_name' => $supplierName,
                'eta'           => $eta ?: null,
                'updated_by'    => auth()->id(),
            ]
        );

        $this->schedule->load('components');
        $this->dispatch('component-updated');
    }

    public function deleteComponent(int $componentId): void
    {
        if (!$this->canEdit()) {
            return;
        }

        ProductionScheduleComponent::where('id', $componentId)->delete();
        $this->schedule->load('components');
        $this->dispatch('component-updated');
    }

    public function render(): View
    {
        $this->schedule->load(['proformaInvoice.items.product', 'components']);

        $items = $this->schedule->proformaInvoice->items
            ->map(function ($piItem) {
                $components    = $this->schedule->components
                    ->where('proforma_invoice_item_id', $piItem->id);
                $productLevel  = $components->firstWhere('component_name', null);
                $subComponents = $components->whereNotNull('component_name')->values();

                return [
                    'id'            => $piItem->id,
                    'name'          => $piItem->product?->name ?? $piItem->description ?? '—',
                    'productLevel'  => $productLevel,
                    'subComponents' => $subComponents,
                ];
            })
            ->values();

        return view('livewire.supplier-portal.component-inventory-panel', [
            'items'           => $items,
            'componentStatuses' => ComponentStatus::cases(),
        ]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
{{-- resources/views/livewire/supplier-portal/component-inventory-panel.blade.php --}}
<div wire:key="ps-components-{{ $schedule->id }}"
     class="border border-gray-200 dark:border-white/10 rounded-lg">

    {{-- Panel header --}}
    <button wire:click="$toggle('isExpanded')"
            class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 rounded-lg text-left hover:bg-gray-100 dark:hover:bg-white/10 transition-colors">
        <div class="flex items-center gap-2 font-semibold text-sm text-gray-700 dark:text-gray-200">
            <x-heroicon-o-wrench-screwdriver class="w-4 h-4"/>
            Component / Parts Inventory
        </div>
        <x-heroicon-o-chevron-down class="w-4 h-4 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}"/>
    </button>

    @if($isExpanded)
        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach($items as $item)
                @php
                    $isExpanded_item = in_array($item['id'], $expandedItems);
                    $pl = $item['productLevel'];
                @endphp
                <div class="px-4 py-3">
                    {{-- Product row --}}
                    <div class="flex items-center gap-3">
                        <span class="font-medium text-sm text-gray-800 dark:text-gray-200 flex-1">
                            {{ $item['name'] }}
                        </span>

                        {{-- Product-level status --}}
                        @if($pl)
                            <x-filament::badge :color="$pl->status->getColor()">
                                {{ $pl->status->getLabel() }}
                                @if($pl->eta) · ETA {{ $pl->eta->format('d/m/Y') }} @endif
                            </x-filament::badge>
                        @endif

                        @if($this->canEdit())
                            {{-- Quick status select for product level --}}
                            <select
                                wire:change="saveComponent({{ $item['id'] }}, null, $event.target.value, null, null)"
                                class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 text-gray-700 dark:text-gray-300">
                                <option value="">Set status...</option>
                                @foreach($componentStatuses as $status)
                                    <option value="{{ $status->value }}"
                                        {{ $pl?->status === $status ? 'selected' : '' }}>
                                        {{ $status->getLabel() }}
                                    </option>
                                @endforeach
                            </select>

                            <button wire:click="toggleExpand({{ $item['id'] }})"
                                    class="text-xs text-primary-600 hover:underline">
                                {{ $isExpanded_item ? 'Hide' : 'Sub-components' }}
                            </button>
                        @elseif(count($item['subComponents']) > 0)
                            <button wire:click="toggleExpand({{ $item['id'] }})"
                                    class="text-xs text-primary-600 hover:underline">
                                {{ count($item['subComponents']) }} component(s)
                            </button>
                        @endif
                    </div>

                    {{-- Sub-components (expanded) --}}
                    @if($isExpanded_item)
                        <div class="mt-2 ml-4 space-y-1.5">
                            @foreach($item['subComponents'] as $comp)
                                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                    <span class="flex-1 font-medium">{{ $comp->component_name }}</span>
                                    <x-filament::badge size="sm" :color="$comp->status->getColor()">
                                        {{ $comp->status->getLabel() }}
                                    </x-filament::badge>
                                    @if($comp->supplier_name)
                                        <span class="text-gray-400">{{ $comp->supplier_name }}</span>
                                    @endif
                                    @if($comp->eta)
                                        <span class="{{ $comp->eta->isPast() ? 'text-red-500' : 'text-gray-400' }}">
                                            ETA {{ $comp->eta->format('d/m/Y') }}
                                        </span>
                                    @endif
                                    @if($this->canEdit())
                                        <button wire:click="deleteComponent({{ $comp->id }})"
                                                class="text-red-400 hover:text-red-600">✕</button>
                                    @endif
                                </div>
                            @endforeach

                            @if($this->canEdit())
                                {{-- Add sub-component inline form --}}
                                <div x-data="{ name:'', status:'at_supplier', supplier:'', eta:'' }"
                                     class="flex items-center gap-1.5 pt-1 border-t border-dashed border-gray-200 dark:border-white/10">
                                    <input x-model="name" type="text" placeholder="Component name"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 w-28">
                                    <select x-model="status"
                                            class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5">
                                        @foreach($componentStatuses as $st)
                                            <option value="{{ $st->value }}">{{ $st->getLabel() }}</option>
                                        @endforeach
                                    </select>
                                    <input x-model="supplier" type="text" placeholder="Supplier"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 w-24">
                                    <input x-model="eta" type="date"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5">
                                    <button
                                        @click="$wire.saveComponent({{ $item['id'] }}, name, status, supplier, eta); name=''; supplier=''; eta='';"
                                        class="text-xs bg-primary-600 text-white px-2 py-1 rounded hover:bg-primary-700">
                                        Add
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 5: Write the ViewEntry wrapper**

```blade
{{-- resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php --}}
@php $record = $getRecord(); @endphp
<livewire:supplier-portal.component-inventory-panel
    :schedule="$record"
    :key="'ps-components-' . $record->id"
/>
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php
```

Expected: all 4 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/SupplierPortal/ComponentInventoryPanel.php \
        resources/views/livewire/supplier-portal/component-inventory-panel.blade.php \
        resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php \
        tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php
git commit -m "feat: add ComponentInventoryPanel Livewire component for supplier portal"
```

---

## Task 6: ScheduleApprovalWidget Livewire Component (Client Portal)

**Files:**
- Create: `app/Livewire/Portal/ScheduleApprovalWidget.php`
- Create: `resources/views/livewire/portal/schedule-approval-widget.blade.php`
- Create: `tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Livewire\Portal\ScheduleApprovalWidget;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(\App\Models\User::factory()->create()));

it('approves schedule and transitions status', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::PendingApproval,
    ]);

    Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
        ->call('approve');

    $schedule->refresh();
    expect($schedule->status)->toBe(ProductionScheduleStatus::Approved);
    expect($schedule->approved_at)->not->toBeNull();
    expect($schedule->approved_by)->toBe(auth()->id());
});

it('rejects schedule with a note', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::PendingApproval,
    ]);

    Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
        ->set('approvalNote', 'Quantities too low for week 2')
        ->call('reject');

    $schedule->refresh();
    expect($schedule->status)->toBe(ProductionScheduleStatus::Rejected);
    expect($schedule->approval_notes)->toBe('Quantities too low for week 2');
});

it('requires a note when rejecting', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::PendingApproval,
    ]);

    Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
        ->set('approvalNote', '')
        ->call('reject')
        ->assertHasErrors(['approvalNote']);

    expect($schedule->fresh()->status)->toBe(ProductionScheduleStatus::PendingApproval);
});

it('does not show approve/reject when status is not pending_approval', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
        ->assertDontSee('Approve Schedule');
});
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php artisan test tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php
// app/Livewire/Portal/ScheduleApprovalWidget.php

namespace App\Livewire\Portal;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ScheduleApprovalWidget extends Component
{
    public ProductionSchedule $schedule;
    public string $approvalNote = '';

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
    }

    public function approve(): void
    {
        if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) {
            return;
        }

        $this->schedule->update([
            'status'      => ProductionScheduleStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        $this->schedule->refresh();

        Notification::make()->success()->title('Schedule approved')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function reject(): void
    {
        if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) {
            return;
        }

        $this->validate(['approvalNote' => 'required|string|min:5']);

        $this->schedule->update([
            'status'         => ProductionScheduleStatus::Rejected,
            'approved_by'    => auth()->id(),
            'approved_at'    => now(),
            'approval_notes' => $this->approvalNote,
        ]);
        $this->schedule->refresh();

        Notification::make()->warning()->title('Schedule rejected — supplier will be notified')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function render(): View
    {
        $this->schedule->load(['entries.proformaInvoiceItem.product']);

        $entries = $this->schedule->entries;

        $items = $this->schedule->proformaInvoice->items()
            ->with('product')
            ->get()
            ->map(function ($piItem) use ($entries) {
                $itemEntries = $entries->where('proforma_invoice_item_id', $piItem->id);
                $dates       = $itemEntries->pluck('production_date')
                    ->map->format('Y-m-d')
                    ->sort()
                    ->values();
                $quantities  = $itemEntries->pluck('quantity', 'production_date');

                return [
                    'id'          => $piItem->id,
                    'name'        => $piItem->product?->name ?? $piItem->description ?? '—',
                    'pi_quantity' => $piItem->quantity,
                    'dates'       => $dates,
                    'quantities'  => $quantities,
                    'total'       => $itemEntries->sum('quantity'),
                ];
            });

        $allDates = $entries->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values();

        return view('livewire.portal.schedule-approval-widget', [
            'items'    => $items,
            'allDates' => $allDates,
            'isPending' => $this->schedule->status === ProductionScheduleStatus::PendingApproval,
        ]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
{{-- resources/views/livewire/portal/schedule-approval-widget.blade.php --}}
<div wire:key="approval-{{ $schedule->id }}" class="space-y-4">

    {{-- Status banner --}}
    @if($isPending)
        <div class="flex items-center gap-3 px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
            <x-heroicon-o-clock class="w-5 h-5 text-amber-500 shrink-0"/>
            <div class="flex-1">
                <p class="font-semibold text-amber-800 dark:text-amber-200 text-sm">
                    Production schedule awaiting your approval
                </p>
                @if($schedule->submitted_at)
                    <p class="text-xs text-amber-600 dark:text-amber-400">
                        Submitted {{ $schedule->submitted_at->format('d/m/Y H:i') }}
                    </p>
                @endif
            </div>
        </div>
    @elseif($schedule->status->value === 'approved')
        <div class="flex items-center gap-2 px-4 py-2 bg-green-50 dark:bg-green-900/20 border border-green-200 rounded-lg text-sm text-green-700 dark:text-green-300">
            <x-heroicon-o-check-circle class="w-4 h-4"/>
            Approved on {{ $schedule->approved_at?->format('d/m/Y') }}
        </div>
    @elseif($schedule->status->value === 'rejected')
        <div class="px-4 py-3 bg-red-50 dark:bg-red-900/20 border border-red-200 rounded-lg text-sm text-red-700 dark:text-red-300">
            <div class="flex items-center gap-2 font-semibold">
                <x-heroicon-o-x-circle class="w-4 h-4"/> Rejected
            </div>
            @if($schedule->approval_notes)
                <p class="mt-1 text-xs">Note: {{ $schedule->approval_notes }}</p>
            @endif
        </div>
    @endif

    {{-- Read-only grid --}}
    @if(count($allDates) > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 text-xs font-medium">
                    <tr>
                        <th class="px-4 py-2.5 text-left min-w-[140px]">Product</th>
                        <th class="px-3 py-2.5 text-center">PI Qty</th>
                        @foreach($allDates as $date)
                            <th class="px-3 py-2.5 text-center min-w-[80px]">
                                {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}
                            </th>
                        @endforeach
                        <th class="px-3 py-2.5 text-center">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach($items as $item)
                        @php $diff = $item['total'] - $item['pi_quantity']; @endphp
                        <tr class="text-gray-900 dark:text-white">
                            <td class="px-4 py-2.5 font-medium">{{ $item['name'] }}</td>
                            <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($item['pi_quantity']) }}</td>
                            @foreach($allDates as $date)
                                <td class="px-3 py-2.5 text-center">
                                    {{ isset($item['quantities'][$date]) ? number_format($item['quantities'][$date]) : '—' }}
                                </td>
                            @endforeach
                            <td class="px-3 py-2.5 text-center font-bold {{ $diff >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600' }}">
                                {{ number_format($item['total']) }}
                                @if($diff < 0) <span class="text-xs">(⚠️ short {{ abs($diff) }})</span> @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Approval actions --}}
    @if($isPending)
        <div class="space-y-3 p-4 bg-gray-50 dark:bg-white/5 rounded-lg border border-gray-200 dark:border-white/10">
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                    Note (required if rejecting)
                </label>
                <textarea
                    wire:model="approvalNote"
                    rows="2"
                    placeholder="Add a note for the supplier..."
                    class="w-full text-sm border border-gray-300 dark:border-white/20 rounded-lg px-3 py-2 bg-white dark:bg-white/5 text-gray-900 dark:text-white resize-none focus:ring-2 focus:ring-primary-500">
                </textarea>
                @error('approvalNote')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-3">
                <x-filament::button
                    wire:click="approve"
                    wire:loading.attr="disabled"
                    color="success"
                    icon="heroicon-o-check-circle"
                    class="flex-1">
                    Approve Schedule
                </x-filament::button>
                <x-filament::button
                    wire:click="reject"
                    wire:loading.attr="disabled"
                    color="danger"
                    icon="heroicon-o-x-circle"
                    class="flex-1">
                    Reject
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php
```

Expected: all 4 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Portal/ScheduleApprovalWidget.php \
        resources/views/livewire/portal/schedule-approval-widget.blade.php \
        tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php
git commit -m "feat: add ScheduleApprovalWidget Livewire component for client portal"
```

---

## Task 7: ProductionActualsGrid Livewire Component (Admin)

**Files:**
- Create: `app/Livewire/Admin/ProductionActualsGrid.php`
- Create: `resources/views/livewire/admin/production-actuals-grid.blade.php`
- Create: `resources/views/filament/production-schedule/actuals-grid-entry.blade.php`
- Create: `tests/Feature/Livewire/Admin/ProductionActualsGridTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Livewire/Admin/ProductionActualsGridTest.php

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\Admin\ProductionActualsGrid;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(\App\Models\User::factory()->create()));

it('saves actual quantity to entry', function () {
    $pi     = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory(), 'items')->create();
    $item   = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Approved,
    ]);
    $entry = ProductionScheduleEntry::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
        'production_date'          => '2025-04-10',
        'quantity'                 => 100,
        'actual_quantity'          => null,
    ]);

    Livewire::test(ProductionActualsGrid::class, ['schedule' => $schedule])
        ->call('updateActual', $item->id, '2025-04-10', '95');

    expect($entry->fresh()->actual_quantity)->toBe(95);
});

it('marks schedule as completed when all actuals meet planned quantities', function () {
    $pi   = ProformaInvoice::factory()->has(ProformaInvoiceItem::factory()->state(['quantity' => 100]), 'items')->create();
    $item = $pi->items->first();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Approved,
    ]);
    ProductionScheduleEntry::factory()->create([
        'production_schedule_id'   => $schedule->id,
        'proforma_invoice_item_id' => $item->id,
        'production_date'          => '2025-04-10',
        'quantity'                 => 100,
        'actual_quantity'          => null,
    ]);

    Livewire::test(ProductionActualsGrid::class, ['schedule' => $schedule])
        ->call('updateActual', $item->id, '2025-04-10', '100')
        ->call('saveActuals');

    expect($schedule->fresh()->status)->toBe(ProductionScheduleStatus::Completed);
});

it('does not render when status is not approved or completed', function () {
    $pi       = ProformaInvoice::factory()->create();
    $schedule = ProductionSchedule::factory()->create([
        'proforma_invoice_id' => $pi->id,
        'status'              => ProductionScheduleStatus::Draft,
    ]);

    Livewire::test(ProductionActualsGrid::class, ['schedule' => $schedule])
        ->assertDontSee('Save Actuals');
});
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php artisan test tests/Feature/Livewire/Admin/ProductionActualsGridTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php
// app/Livewire/Admin/ProductionActualsGrid.php

namespace App\Livewire\Admin;

use App\Domain\Planning\Actions\UpdatePaymentScheduleFromProductionAction;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ProductionActualsGrid extends Component
{
    public ProductionSchedule $schedule;

    /** @var array<string, array<string, int|null>> ['item-{id}']['YYYY-MM-DD'] = actual_quantity */
    public array $actuals = [];

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->loadActuals();
    }

    public function loadActuals(): void
    {
        $this->schedule->load('entries');

        foreach ($this->schedule->entries as $entry) {
            $dateKey  = $entry->production_date->format('Y-m-d');
            $itemKey  = 'item-' . $entry->proforma_invoice_item_id;
            $this->actuals[$itemKey][$dateKey] = $entry->actual_quantity;
        }
    }

    public function isVisible(): bool
    {
        return in_array($this->schedule->status, [
            ProductionScheduleStatus::Approved,
            ProductionScheduleStatus::Completed,
        ]);
    }

    public function updateActual(int $itemId, string $date, ?string $value): void
    {
        if (!$this->isVisible()) {
            return;
        }

        $quantity = ($value !== null && $value !== '') ? max(0, (int) $value) : null;
        $itemKey  = 'item-' . $itemId;

        $this->actuals[$itemKey][$date] = $quantity;

        ProductionScheduleEntry::where([
            'production_schedule_id'   => $this->schedule->id,
            'proforma_invoice_item_id' => $itemId,
            'production_date'          => $date,
        ])->update(['actual_quantity' => $quantity]);
    }

    public function saveActuals(): void
    {
        if (!$this->isVisible()) {
            return;
        }

        app(UpdatePaymentScheduleFromProductionAction::class)->execute($this->schedule);

        $this->schedule->refresh();
        $totalPlanned = $this->schedule->entries->sum('quantity');
        $totalActual  = $this->schedule->entries->sum(fn ($e) => $e->actual_quantity ?? 0);

        if ($totalPlanned > 0 && $totalActual >= $totalPlanned) {
            $this->schedule->update(['status' => ProductionScheduleStatus::Completed]);
            $this->schedule->refresh();
        }

        Notification::make()->success()->title('Actuals saved')->send();
        $this->dispatch('actuals-saved');
    }

    public function render(): View
    {
        $this->schedule->load(['proformaInvoice.items.product', 'entries']);

        $today = now()->format('Y-m-d');

        $items = $this->schedule->proformaInvoice->items
            ->map(fn ($piItem) => [
                'id'   => $piItem->id,
                'name' => $piItem->product?->name ?? $piItem->description ?? '—',
                'sku'  => $piItem->product?->sku ?? '',
            ])
            ->values()
            ->toArray();

        $dates = $this->schedule->entries
            ->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $planned = [];
        foreach ($this->schedule->entries as $entry) {
            $planned['item-' . $entry->proforma_invoice_item_id][$entry->production_date->format('Y-m-d')] = $entry->quantity;
        }

        return view('livewire.admin.production-actuals-grid', [
            'items'     => $items,
            'dates'     => $dates,
            'planned'   => $planned,
            'today'     => $today,
            'isVisible' => $this->isVisible(),
        ]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
{{-- resources/views/livewire/admin/production-actuals-grid.blade.php --}}
<div wire:key="actuals-grid-{{ $schedule->id }}">
    @if(!$isVisible)
        <p class="text-sm text-gray-400 italic p-4">
            Actuals entry is available once the schedule is approved.
        </p>
    @else
        <div class="space-y-4">
            {{-- Progress bar --}}
            @php
                $totalPlanned = collect($planned)->map(fn($d) => array_sum($d))->sum();
                $totalActual  = collect($actuals)->map(fn($d) => array_sum(array_filter($d, fn($v) => $v !== null)))->sum();
                $pct = $totalPlanned > 0 ? min(100, round(($totalActual / $totalPlanned) * 100)) : 0;
            @endphp
            <div class="flex items-center gap-3 px-1">
                <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2">
                    <div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-green-500' : ($pct > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                         style="width: {{ $pct }}%"></div>
                </div>
                <span class="text-sm font-semibold {{ $pct >= 100 ? 'text-green-600' : 'text-gray-600 dark:text-gray-300' }}">
                    {{ $pct }}% ({{ number_format($totalActual) }} / {{ number_format($totalPlanned) }})
                </span>
            </div>

            {{-- Grid --}}
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-xs font-medium text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2.5 text-left min-w-[150px]">Product</th>
                            @foreach($dates as $date)
                                @php $isToday = $date === $today; $isPast = $date < $today; @endphp
                                <th class="px-3 py-2.5 text-center min-w-[100px] {{ $isToday ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                    <div class="{{ $isToday ? 'text-blue-600 dark:text-blue-400 font-bold' : '' }}">
                                        {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}
                                    </div>
                                    <div class="font-normal">Plan / Actual</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($items as $item)
                            @php $itemKey = 'item-' . $item['id']; @endphp
                            <tr class="text-gray-900 dark:text-white">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium">{{ $item['name'] }}</div>
                                    @if($item['sku'])
                                        <div class="text-xs text-gray-400">{{ $item['sku'] }}</div>
                                    @endif
                                </td>
                                @foreach($dates as $date)
                                    @php
                                        $plan   = $planned[$itemKey][$date] ?? null;
                                        $actual = $actuals[$itemKey][$date] ?? null;
                                        $isToday = $date === $today;
                                        $isPast  = $date < $today;
                                        $bgClass = $isToday
                                            ? 'bg-blue-50 dark:bg-blue-900/20'
                                            : ($isPast && $actual !== null
                                                ? ($actual >= ($plan ?? 0) ? 'bg-green-50 dark:bg-green-900/20' : 'bg-amber-50 dark:bg-amber-900/20')
                                                : '');
                                    @endphp
                                    <td class="px-2 py-1.5 text-center {{ $bgClass }}">
                                        <div class="text-xs text-gray-400 mb-0.5">{{ $plan ? number_format($plan) : '—' }}</div>
                                        @if($date <= $today)
                                            <input
                                                type="number"
                                                min="0"
                                                value="{{ $actual ?? '' }}"
                                                placeholder="—"
                                                wire:change="updateActual({{ $item['id'] }}, '{{ $date }}', $event.target.value)"
                                                class="w-20 text-center text-sm border border-gray-300 dark:border-white/20 rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500"
                                            >
                                        @else
                                            <span class="text-gray-400 text-sm">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Save button --}}
            <div class="flex justify-end">
                <x-filament::button
                    wire:click="saveActuals"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-check"
                    color="primary">
                    Save Actuals
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Write the ViewEntry wrapper**

```blade
{{-- resources/views/filament/production-schedule/actuals-grid-entry.blade.php --}}
@php $record = $getRecord(); @endphp
<livewire:admin.production-actuals-grid
    :schedule="$record"
    :key="'actuals-' . $record->id"
/>
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Livewire/Admin/ProductionActualsGridTest.php
```

Expected: all 3 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/ProductionActualsGrid.php \
        resources/views/livewire/admin/production-actuals-grid.blade.php \
        resources/views/filament/production-schedule/actuals-grid-entry.blade.php \
        tests/Feature/Livewire/Admin/ProductionActualsGridTest.php
git commit -m "feat: add ProductionActualsGrid Livewire component for admin"
```

---

## Task 8: Wire Up Filament Pages

**Files:**
- Create: `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/CreateProductionSchedule.php`
- Modify: `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php`
- Modify: `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php`
- Modify: `app/Filament/Resources/ProductionSchedules/Pages/ViewProductionSchedule.php`
- Modify: `resources/views/portal/infolists/pi-production-progress.blade.php`

- [ ] **Step 1: Create supplier portal Create page**

```php
<?php
// app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/CreateProductionSchedule.php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Filament\SupplierPortal\Resources\ProductionScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionSchedule extends CreateRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_company_id'] = auth()->user()->company_id
            ?? $this->getTenant()?->id;
        $data['status']     = ProductionScheduleStatus::Draft->value;
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
```

- [ ] **Step 2: Update supplier portal ViewProductionSchedule — replace relation manager with Livewire sections**

Replace the entire file with:

```php
<?php
// app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;

use App\Filament\SupplierPortal\Resources\ProductionScheduleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProductionSchedule extends ViewRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    public function getRelationManagers(): array
    {
        return []; // Replaced by Livewire components in infolist
    }
}
```

- [ ] **Step 3: Update supplier portal ProductionScheduleResource — status column, create form, updated infolist**

Replace the `table()` method's columns array — add status column after reference:
```php
TextColumn::make('status')
    ->badge()
    ->sortable(),
```

Replace `canCreate()`:
```php
public static function canCreate(): bool
{
    return auth()->user()?->can('supplier-portal:manage-production-schedule') ?? false;
}
```

Add `getPages()` create route:
```php
'create' => Pages\CreateProductionSchedule::route('/create'),
```

Replace the `infolist()` method with:
```php
public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        Section::make(__('Production Schedule Details'))
            ->schema([
                TextEntry::make('reference')->copyable()->weight('bold'),
                TextEntry::make('status')->badge(),
                TextEntry::make('purchaseOrder.reference')->label('PO Reference')->placeholder('—'),
                TextEntry::make('received_date')->label('Received Date')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('version')->label('Version'),
                TextEntry::make('submitted_at')
                    ->label('Submitted At')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextEntry::make('approval_notes')
                    ->label('Rejection Notes')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn ($record) => $record->status->value === 'rejected'),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ])
            ->columns(3)
            ->columnSpanFull(),

        Section::make('Production Grid')
            ->schema([
                ViewEntry::make('production_grid')
                    ->view('filament.supplier-portal.production-schedule-grid-entry')
                    ->columnSpanFull(),
            ])
            ->columnSpanFull(),

        Section::make('Component / Parts Inventory')
            ->schema([
                ViewEntry::make('components_panel')
                    ->view('filament.supplier-portal.component-inventory-panel-entry')
                    ->columnSpanFull(),
            ])
            ->columnSpanFull(),
    ]);
}
```

Add `ViewEntry` to the imports at the top of the resource file:
```php
use Filament\Infolists\Components\ViewEntry;
```

Add the create form via `schema()` static method:
```php
public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
{
    return $schema->components([
        \Filament\Schemas\Components\Section::make('New Production Schedule')
            ->schema([
                \Filament\Forms\Components\Select::make('proforma_invoice_id')
                    ->label('Proforma Invoice')
                    ->relationship('proformaInvoice', 'reference')
                    ->searchable()
                    ->preload()
                    ->required(),
                \Filament\Forms\Components\Select::make('purchase_order_id')
                    ->label('Purchase Order (optional)')
                    ->relationship('purchaseOrder', 'reference')
                    ->searchable()
                    ->nullable(),
            ])
            ->columns(2),
    ]);
}
```

- [ ] **Step 4: Add actuals grid section to admin ViewProductionSchedule**

In `app/Filament/Resources/ProductionSchedules/Pages/ViewProductionSchedule.php`, add a new protected method and call it from `getHeaderActions()` context. Actually add after the existing `protected function getHeaderActions()`:

```php
protected function getFooterWidgets(): array
{
    return [];
}
```

And update the resource's `infolist()` in `app/Filament/Resources/ProductionSchedules/Schemas/ProductionScheduleInfolist.php` to add a new section at the end:

```php
\Filament\Schemas\Components\Section::make('Production Actuals')
    ->schema([
        \Filament\Infolists\Components\ViewEntry::make('actuals_grid')
            ->view('filament.production-schedule.actuals-grid-entry')
            ->columnSpanFull(),
    ])
    ->columnSpanFull(),
```

- [ ] **Step 5: Add ScheduleApprovalWidget to client portal PI production progress view**

In `resources/views/portal/infolists/pi-production-progress.blade.php`, find the closing `@endif` at the bottom and add before it, inside the `@if($hasData)` block:

```blade
{{-- Approval widgets per schedule --}}
@foreach($schedules->where('status', 'pending_approval') as $pendingSchedule)
    <div class="mt-4">
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
            Schedule {{ $pendingSchedule->reference }} — Pending Your Approval
        </h4>
        <livewire:portal.schedule-approval-widget
            :schedule="$pendingSchedule"
            :key="'approval-widget-' . $pendingSchedule->id"
        />
    </div>
@endforeach
```

- [ ] **Step 6: Run the full test suite**

```bash
php artisan test
```

Expected: all tests pass, no regressions.

- [ ] **Step 7: Build assets**

```bash
npm run build
```

Expected: builds without errors.

- [ ] **Step 8: Commit**

```bash
git add \
  app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/CreateProductionSchedule.php \
  app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php \
  app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php \
  app/Filament/Resources/ProductionSchedules/Schemas/ProductionScheduleInfolist.php \
  resources/views/portal/infolists/pi-production-progress.blade.php
git commit -m "feat: wire up Filament pages with Livewire production schedule components"
```

---

## Post-Implementation Checklist

- [ ] Visit supplier portal → Production Schedules → Create a new schedule, add dates, submit for approval
- [ ] Visit client portal → Proforma Invoice → confirm approval widget appears for pending schedules
- [ ] Visit client portal → approve a schedule
- [ ] Visit admin → Production Schedule view → confirm actuals grid appears, enter actuals, save
- [ ] Verify status transitions: draft → pending → approved → completed
- [ ] Verify component ⚠️ risk indicators appear in supplier grid when ETA > production date
