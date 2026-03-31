# Component Delivery Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken ComponentInventoryPanel with a delivery-tracking grid (dates as columns) for supplier components, and redesign the client portal production progress to use the same grid layout with component visibility.

**Architecture:** New `component_deliveries` table tracks expected/received quantities per date per component. New `ComponentDeliveryGrid` Livewire component replaces `ComponentInventoryPanel`. Client portal blade rewritten as a grid with dates as columns. Components auto-populated from product BOM with `quantity_required = bom_qty × pi_qty`.

**Tech Stack:** Laravel 12, Livewire 3, Filament v4, Pest for tests.

---

## File Map

### Create
| File | Purpose |
|------|---------|
| `database/migrations/2026_03_31_110000_add_quantity_required_to_production_schedule_components.php` | Add `quantity_required` column |
| `database/migrations/2026_03_31_120000_create_component_deliveries_table.php` | Deliveries table |
| `app/Domain/Planning/Models/ComponentDelivery.php` | New model |
| `app/Livewire/SupplierPortal/ComponentDeliveryGrid.php` | New grid component |
| `resources/views/livewire/supplier-portal/component-delivery-grid.blade.php` | Grid blade |
| `resources/views/filament/supplier-portal/component-delivery-grid-entry.blade.php` | ViewEntry wrapper |
| `tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php` | Tests |

### Modify
| File | Change |
|------|--------|
| `app/Domain/Planning/Models/ProductionScheduleComponent.php` | Add `quantity_required` to fillable/casts, add `deliveries()` relationship |
| `app/Domain/Planning/Actions/PopulateScheduleComponentsFromProductAction.php` | Calculate `quantity_required` from BOM × PI qty |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php` | Swap ViewEntry from component-inventory-panel to component-delivery-grid |
| `resources/views/portal/infolists/pi-production-progress.blade.php` | Full rewrite: grid layout + components section |

### Delete
| File | Reason |
|------|--------|
| `app/Livewire/SupplierPortal/ComponentInventoryPanel.php` | Replaced by ComponentDeliveryGrid |
| `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php` | Replaced |
| `resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php` | Replaced |
| `tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php` | Replaced |

---

## Task 0: Migrations

**Files:**
- Create: `database/migrations/2026_03_31_110000_add_quantity_required_to_production_schedule_components.php`
- Create: `database/migrations/2026_03_31_120000_create_component_deliveries_table.php`

- [ ] **Step 1: Write migration to add quantity_required**

```php
<?php
// database/migrations/2026_03_31_110000_add_quantity_required_to_production_schedule_components.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedule_components', function (Blueprint $table) {
            $table->unsignedInteger('quantity_required')->default(0)->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('production_schedule_components', function (Blueprint $table) {
            $table->dropColumn('quantity_required');
        });
    }
};
```

- [ ] **Step 2: Write migration for component_deliveries**

```php
<?php
// database/migrations/2026_03_31_120000_create_component_deliveries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_component_id')
                ->constrained('production_schedule_components')->cascadeOnDelete();
            $table->date('expected_date');
            $table->unsignedInteger('expected_qty');
            $table->unsignedInteger('received_qty')->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('production_schedule_component_id', 'comp_deliveries_component_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_deliveries');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
cd /Users/guidutra/PhpstormProjects/Impex_Main_app/.worktrees/production-schedule-redesign
php artisan migrate
```

Expected: both migrations run without errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_03_31_110000_add_quantity_required_to_production_schedule_components.php \
        database/migrations/2026_03_31_120000_create_component_deliveries_table.php
git commit -m "feat: add quantity_required column and create component_deliveries table"
```

---

## Task 1: ComponentDelivery Model & Update Existing Models

**Files:**
- Create: `app/Domain/Planning/Models/ComponentDelivery.php`
- Modify: `app/Domain/Planning/Models/ProductionScheduleComponent.php`
- Modify: `app/Domain/Planning/Actions/PopulateScheduleComponentsFromProductAction.php`

- [ ] **Step 1: Create ComponentDelivery model**

```php
<?php
// app/Domain/Planning/Models/ComponentDelivery.php

namespace App\Domain\Planning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentDelivery extends Model
{
    protected $fillable = [
        'production_schedule_component_id',
        'expected_date',
        'expected_qty',
        'received_qty',
        'received_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_date'  => 'date',
            'expected_qty'   => 'integer',
            'received_qty'   => 'integer',
            'received_date'  => 'date',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductionScheduleComponent::class, 'production_schedule_component_id');
    }

    public function isReceived(): bool
    {
        return $this->received_qty !== null;
    }
}
```

- [ ] **Step 2: Update ProductionScheduleComponent model**

Open `app/Domain/Planning/Models/ProductionScheduleComponent.php`.

Add `'quantity_required'` to the `$fillable` array (after `'supplier_name'`).

Add to `casts()`:
```php
'quantity_required' => 'integer',
```

Add after the `updatedBy()` relationship:
```php
public function deliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ComponentDelivery::class, 'production_schedule_component_id')
        ->orderBy('expected_date');
}

public function totalReceived(): int
{
    return $this->deliveries->sum(fn ($d) => $d->received_qty ?? 0);
}

public function progressPercent(): int
{
    if ($this->quantity_required <= 0) {
        return 0;
    }
    return min(100, (int) round(($this->totalReceived() / $this->quantity_required) * 100));
}
```

- [ ] **Step 3: Update PopulateScheduleComponentsFromProductAction**

Replace the entire `execute` method in `app/Domain/Planning/Actions/PopulateScheduleComponentsFromProductAction.php`:

```php
public function execute(ProductionSchedule $schedule): int
{
    $schedule->load(['proformaInvoice.items.product.components']);

    $created = 0;

    foreach ($schedule->proformaInvoice->items as $piItem) {
        if (!$piItem->product || $piItem->product->components->isEmpty()) {
            continue;
        }

        // Skip if this PI item already has components in this schedule
        $existingCount = ProductionScheduleComponent::where([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $piItem->id,
        ])->whereNotNull('component_name')->count();

        if ($existingCount > 0) {
            continue;
        }

        foreach ($piItem->product->components as $bomComponent) {
            ProductionScheduleComponent::create([
                'production_schedule_id'   => $schedule->id,
                'proforma_invoice_item_id' => $piItem->id,
                'component_name'           => $bomComponent->name,
                'status'                   => ComponentStatus::AtSupplier,
                'supplier_name'            => $bomComponent->default_supplier_name,
                'quantity_required'         => (int) ($bomComponent->quantity_required * $piItem->quantity),
                'eta'                      => null,
                'notes'                    => null,
                'updated_by'               => null,
            ]);
            $created++;
        }
    }

    return $created;
}
```

The only change is the addition of:
```php
'quantity_required' => (int) ($bomComponent->quantity_required * $piItem->quantity),
```

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Planning/Models/ComponentDelivery.php \
        app/Domain/Planning/Models/ProductionScheduleComponent.php \
        app/Domain/Planning/Actions/PopulateScheduleComponentsFromProductAction.php
git commit -m "feat: add ComponentDelivery model, update component with quantity_required and deliveries"
```

---

## Task 2: ComponentDeliveryGrid Livewire Component (Supplier Portal)

**Files:**
- Create: `tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php`
- Create: `app/Livewire/SupplierPortal/ComponentDeliveryGrid.php`
- Create: `resources/views/livewire/supplier-portal/component-delivery-grid.blade.php`
- Create: `resources/views/filament/supplier-portal/component-delivery-grid-entry.blade.php`

- [ ] **Step 1: Write tests**

```php
<?php
// tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php

namespace Tests\Feature\Livewire\SupplierPortal;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ComponentDelivery;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ComponentDeliveryGrid;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class ComponentDeliveryGridTest extends TestCase
{
    private ProductionSchedule $schedule;
    private ProductionScheduleComponent $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $pi = ProformaInvoice::factory()
            ->has(ProformaInvoiceItem::factory()->state(['quantity' => 100]), 'items')
            ->create();
        $item = $pi->items->first();

        $this->schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'status'              => ProductionScheduleStatus::Draft,
        ]);

        $this->component = ProductionScheduleComponent::create([
            'production_schedule_id'   => $this->schedule->id,
            'proforma_invoice_item_id' => $item->id,
            'component_name'           => 'LCD Panel',
            'status'                   => ComponentStatus::AtSupplier,
            'supplier_name'            => 'Supplier X',
            'quantity_required'        => 100,
        ]);
    }

    public function test_renders_component_with_quantity_required(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->assertSee('LCD Panel')
            ->assertSee('100');
    }

    public function test_adds_expected_delivery(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('addDelivery', $this->component->id, '2025-04-10', 25);

        $this->assertDatabaseHas('component_deliveries', [
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10',
            'expected_qty'                     => 25,
            'received_qty'                     => null,
        ]);
    }

    public function test_marks_delivery_as_received(): void
    {
        $delivery = ComponentDelivery::create([
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10',
            'expected_qty'                     => 25,
        ]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('markReceived', $delivery->id, 25);

        $delivery->refresh();
        $this->assertEquals(25, $delivery->received_qty);
        $this->assertNotNull($delivery->received_date);
    }

    public function test_removes_delivery(): void
    {
        $delivery = ComponentDelivery::create([
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10',
            'expected_qty'                     => 25,
        ]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('removeDelivery', $delivery->id);

        $this->assertDatabaseMissing('component_deliveries', ['id' => $delivery->id]);
    }

    public function test_does_not_allow_edits_when_approved(): void
    {
        $this->schedule->update(['status' => ProductionScheduleStatus::Approved]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('addDelivery', $this->component->id, '2025-04-10', 25);

        $this->assertEquals(0, ComponentDelivery::count());
    }

    public function test_adds_date_column(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->set('newDateInput', '2025-04-17')
            ->call('addDateColumn')
            ->assertSet('newDateInput', null);

        // A delivery entry should be created for each component with qty 0
        $this->assertDatabaseHas('component_deliveries', [
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-17',
            'expected_qty'                     => 0,
        ]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /Users/guidutra/PhpstormProjects/Impex_Main_app/.worktrees/production-schedule-redesign
php artisan test tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php
```

Expected: FAIL — `ComponentDeliveryGrid` class not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php
// app/Livewire/SupplierPortal/ComponentDeliveryGrid.php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Actions\PopulateScheduleComponentsFromProductAction;
use App\Domain\Planning\Models\ComponentDelivery;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use Illuminate\View\View;
use Livewire\Component;

class ComponentDeliveryGrid extends Component
{
    public ProductionSchedule $schedule;

    public ?string $newDateInput = null;
    public bool $showAddDate = false;

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->autoPopulateFromBom();
    }

    private function autoPopulateFromBom(): void
    {
        if (!$this->canEdit()) {
            return;
        }

        if ($this->schedule->components()->count() > 0) {
            return;
        }

        app(PopulateScheduleComponentsFromProductAction::class)->execute($this->schedule);
    }

    public function canEdit(): bool
    {
        return $this->schedule->status->canBeEditedBySupplier();
    }

    public function addDateColumn(): void
    {
        if (!$this->canEdit() || !$this->newDateInput) {
            return;
        }

        $date = $this->newDateInput;
        $this->newDateInput = null;
        $this->showAddDate = false;

        // Create a delivery entry for each component on this date (with qty 0 as placeholder)
        $this->schedule->load('components');
        foreach ($this->schedule->components as $component) {
            $exists = ComponentDelivery::where([
                'production_schedule_component_id' => $component->id,
                'expected_date'                    => $date,
            ])->exists();

            if (!$exists) {
                ComponentDelivery::create([
                    'production_schedule_component_id' => $component->id,
                    'expected_date'                    => $date,
                    'expected_qty'                     => 0,
                ]);
            }
        }
    }

    public function removeDateColumn(string $date): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        ComponentDelivery::whereIn('production_schedule_component_id', $componentIds)
            ->whereDate('expected_date', $date)
            ->delete();
    }

    public function addDelivery(int $componentId, string $date, int $qty): void
    {
        if (!$this->canEdit()) {
            return;
        }

        // Verify component belongs to this schedule
        $component = $this->schedule->components()->find($componentId);
        if (!$component) {
            return;
        }

        ComponentDelivery::updateOrCreate(
            [
                'production_schedule_component_id' => $componentId,
                'expected_date'                    => $date,
            ],
            ['expected_qty' => max(0, $qty)]
        );
    }

    public function updateExpectedQty(int $deliveryId, ?string $value): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery || $delivery->isReceived()) {
            return;
        }

        // Verify it belongs to this schedule
        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $qty = ($value !== null && $value !== '') ? max(0, (int) $value) : 0;
        $delivery->update(['expected_qty' => $qty]);
    }

    public function markReceived(int $deliveryId, ?int $receivedQty = null): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $delivery->update([
            'received_qty'  => $receivedQty ?? $delivery->expected_qty,
            'received_date' => now()->toDateString(),
        ]);
    }

    public function undoReceived(int $deliveryId): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $delivery->update([
            'received_qty'  => null,
            'received_date' => null,
        ]);
    }

    public function removeDelivery(int $deliveryId): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $delivery->delete();
    }

    public function render(): View
    {
        $this->schedule->load(['components.deliveries', 'proformaInvoice.items.product']);

        $components = $this->schedule->components
            ->map(function (ProductionScheduleComponent $comp) {
                $piItem = $this->schedule->proformaInvoice->items
                    ->firstWhere('id', $comp->proforma_invoice_item_id);

                return [
                    'id'               => $comp->id,
                    'name'             => $comp->component_name,
                    'product_name'     => $piItem?->product?->name ?? $piItem?->description ?? '—',
                    'supplier_name'    => $comp->supplier_name,
                    'quantity_required' => $comp->quantity_required,
                    'total_received'   => $comp->totalReceived(),
                    'progress_percent' => $comp->progressPercent(),
                    'deliveries'       => $comp->deliveries,
                ];
            })
            ->values()
            ->toArray();

        // Collect all unique delivery dates across all components
        $allDates = $this->schedule->components
            ->flatMap(fn ($c) => $c->deliveries->pluck('expected_date'))
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        // Build a lookup: [componentId][date] => delivery
        $deliveryMap = [];
        foreach ($this->schedule->components as $comp) {
            foreach ($comp->deliveries as $delivery) {
                $deliveryMap[$comp->id][$delivery->expected_date->format('Y-m-d')] = $delivery;
            }
        }

        return view('livewire.supplier-portal.component-delivery-grid', [
            'components'  => $components,
            'allDates'    => $allDates,
            'deliveryMap' => $deliveryMap,
        ]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
{{-- resources/views/livewire/supplier-portal/component-delivery-grid.blade.php --}}
<div wire:key="comp-delivery-grid-{{ $schedule->id }}">
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 rounded-t-lg">
        <div class="flex items-center gap-2 font-semibold text-sm text-gray-700 dark:text-gray-200">
            <x-heroicon-o-truck class="w-4 h-4"/>
            Component Deliveries
        </div>
        @if($this->canEdit())
            <x-filament::button size="sm" color="gray" wire:click="$set('showAddDate', true)" icon="heroicon-o-plus">
                Add Delivery Date
            </x-filament::button>
        @endif
    </div>

    {{-- Add Date Input --}}
    @if($showAddDate)
        <div class="flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-white/10">
            <input type="date" wire:model="newDateInput"
                   class="text-sm border border-gray-300 dark:border-white/20 rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white">
            <x-filament::button size="xs" wire:click="addDateColumn">Add</x-filament::button>
            <x-filament::button size="xs" color="gray" wire:click="$set('showAddDate', false)">Cancel</x-filament::button>
        </div>
    @endif

    @if(count($components) === 0)
        <div class="px-4 py-8 text-center text-gray-400 text-sm">
            No components defined in the product BOM. Add components in the product catalog first.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                    <tr>
                        <th class="px-4 py-2.5 min-w-[140px]">Component</th>
                        <th class="px-3 py-2.5 text-center">Product</th>
                        <th class="px-3 py-2.5 text-center">Needed</th>
                        @foreach($allDates as $date)
                            <th class="px-3 py-2.5 text-center min-w-[100px]">
                                <div>{{ \Carbon\Carbon::parse($date)->format('d/m') }}</div>
                                <div class="font-normal text-gray-400">{{ \Carbon\Carbon::parse($date)->format('D') }}</div>
                                @if($this->canEdit())
                                    <button wire:click="removeDateColumn('{{ $date }}')"
                                            class="text-red-400 hover:text-red-600 text-xs mt-0.5">✕</button>
                                @endif
                            </th>
                        @endforeach
                        <th class="px-3 py-2.5 text-center">Received</th>
                        <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach($components as $comp)
                        <tr class="text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-4 py-2.5">
                                <div class="font-medium">{{ $comp['name'] }}</div>
                                @if($comp['supplier_name'])
                                    <div class="text-xs text-gray-400">{{ $comp['supplier_name'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center text-xs text-gray-500">{{ $comp['product_name'] }}</td>
                            <td class="px-3 py-2.5 text-center font-semibold">{{ number_format($comp['quantity_required']) }}</td>
                            @foreach($allDates as $date)
                                @php
                                    $delivery = $deliveryMap[$comp['id']][$date] ?? null;
                                    $isReceived = $delivery?->isReceived() ?? false;
                                @endphp
                                <td class="px-2 py-1.5 text-center {{ $isReceived ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                    @if($delivery)
                                        @if($isReceived)
                                            <div class="font-semibold text-green-600 dark:text-green-400">
                                                {{ number_format($delivery->received_qty) }} ✓
                                            </div>
                                            @if($this->canEdit())
                                                <button wire:click="undoReceived({{ $delivery->id }})"
                                                        class="text-xs text-gray-400 hover:text-gray-600">undo</button>
                                            @endif
                                        @else
                                            @if($this->canEdit())
                                                <div class="flex flex-col items-center gap-0.5">
                                                    <input type="number" min="0"
                                                           value="{{ $delivery->expected_qty ?: '' }}"
                                                           placeholder="—"
                                                           wire:change="updateExpectedQty({{ $delivery->id }}, $event.target.value)"
                                                           class="w-16 text-center text-sm border border-gray-300 dark:border-white/20 rounded px-1 py-0.5 bg-white dark:bg-white/5 text-gray-900 dark:text-white">
                                                    @if($delivery->expected_qty > 0)
                                                        <button wire:click="markReceived({{ $delivery->id }})"
                                                                class="text-xs text-primary-600 hover:underline">✓ received</button>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="{{ $delivery->expected_qty ? '' : 'text-gray-400' }}">
                                                    {{ $delivery->expected_qty ? number_format($delivery->expected_qty) : '—' }}
                                                </span>
                                            @endif
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-2.5 text-center font-bold {{ $comp['total_received'] >= $comp['quantity_required'] ? 'text-green-600 dark:text-green-400' : '' }}">
                                {{ number_format($comp['total_received']) }}/{{ number_format($comp['quantity_required']) }}
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                        <div class="h-2 rounded-full {{ $comp['progress_percent'] >= 100 ? 'bg-green-500' : ($comp['progress_percent'] > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                             style="width: {{ $comp['progress_percent'] }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $comp['progress_percent'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(count($allDates) === 0 && $this->canEdit())
            <div class="px-4 py-6 text-center text-gray-400 text-sm border-t border-gray-200 dark:border-white/10">
                No delivery dates yet. Click <strong>Add Delivery Date</strong> to schedule component arrivals.
            </div>
        @endif
    @endif
</div>
```

- [ ] **Step 5: Write the ViewEntry wrapper**

```blade
{{-- resources/views/filament/supplier-portal/component-delivery-grid-entry.blade.php --}}
@php $record = $getRecord(); @endphp
<livewire:supplier-portal.component-delivery-grid
    :schedule="$record"
    :key="'comp-delivery-' . $record->id"
/>
```

- [ ] **Step 6: Run tests**

```bash
cd /Users/guidutra/PhpstormProjects/Impex_Main_app/.worktrees/production-schedule-redesign
php artisan test tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php
```

Expected: all 6 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/SupplierPortal/ComponentDeliveryGrid.php \
        resources/views/livewire/supplier-portal/component-delivery-grid.blade.php \
        resources/views/filament/supplier-portal/component-delivery-grid-entry.blade.php \
        tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php
git commit -m "feat: add ComponentDeliveryGrid Livewire component for supplier portal"
```

---

## Task 3: Wire Up Supplier Portal & Remove Old Component Panel

**Files:**
- Modify: `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php`
- Delete: `app/Livewire/SupplierPortal/ComponentInventoryPanel.php`
- Delete: `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php`
- Delete: `resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php`
- Delete: `tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php`

- [ ] **Step 1: Update ProductionScheduleResource infolist**

In `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php`, find this section (around line 120-127):

```php
Section::make('Component / Parts Inventory')
    ->schema([
        ViewEntry::make('components_panel')
            ->view('filament.supplier-portal.component-inventory-panel-entry')
            ->columnSpanFull(),
    ])
    ->columnSpanFull(),
```

Replace with:

```php
Section::make('Component Deliveries')
    ->schema([
        ViewEntry::make('component_deliveries')
            ->view('filament.supplier-portal.component-delivery-grid-entry')
            ->columnSpanFull(),
    ])
    ->columnSpanFull(),
```

- [ ] **Step 2: Delete old component panel files**

```bash
cd /Users/guidutra/PhpstormProjects/Impex_Main_app/.worktrees/production-schedule-redesign
rm app/Livewire/SupplierPortal/ComponentInventoryPanel.php
rm resources/views/livewire/supplier-portal/component-inventory-panel.blade.php
rm resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php
rm tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php
```

- [ ] **Step 3: Run full test suite**

```bash
php artisan test --stop-on-failure 2>&1 | tail -15
```

Expected: all tests pass (minus the pre-existing `GeneratePaymentScheduleActionTest` failure).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: replace ComponentInventoryPanel with ComponentDeliveryGrid in supplier portal"
```

---

## Task 4: Redesign Client Portal Production Progress

**Files:**
- Modify: `resources/views/portal/infolists/pi-production-progress.blade.php`

- [ ] **Step 1: Rewrite the entire blade file**

Replace the full content of `resources/views/portal/infolists/pi-production-progress.blade.php` with:

```blade
@php
    $record = $getRecord();
    $schedules = $record->productionSchedules()
        ->with(['entries.proformaInvoiceItem.product', 'components.deliveries'])
        ->get();

    $entries = $schedules->flatMap->entries;

    if ($entries->isEmpty() && $schedules->flatMap->components->isEmpty()) {
        $hasData = false;
    } else {
        $hasData = true;

        // --- PRODUCTION GRID ---
        $piItems = $record->items()->with('product')->get()->keyBy('id');
        $byItem = $entries->groupBy('proforma_invoice_item_id');

        // Collect all production dates
        $productionDates = $entries->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values();

        // Build production rows
        $productionRows = $byItem->map(function ($itemEntries, $piItemId) use ($piItems, $productionDates) {
            $piItem = $piItems[$piItemId] ?? null;
            $piQty = $piItem?->quantity ?? 0;
            $totalActual = $itemEntries->sum(fn ($e) => $e->actual_quantity ?? 0);
            $percent = $piQty > 0 ? min(100, (int) round(($totalActual / $piQty) * 100)) : 0;

            // Map entries by date
            $byDate = [];
            foreach ($itemEntries as $entry) {
                $byDate[$entry->production_date->format('Y-m-d')] = $entry;
            }

            return (object) [
                'product_name'  => $piItem?->product?->name ?? $piItem?->description ?? '—',
                'pi_quantity'   => $piQty,
                'total_actual'  => $totalActual,
                'percent'       => $percent,
                'entries_by_date' => $byDate,
            ];
        })->sortBy('product_name');

        // --- COMPONENTS GRID ---
        $allComponents = $schedules->flatMap->components;
        $componentDates = $allComponents
            ->flatMap(fn ($c) => $c->deliveries->pluck('expected_date'))
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $componentRows = $allComponents->map(function ($comp) use ($piItems) {
            $piItem = $piItems[$comp->proforma_invoice_item_id] ?? null;
            $totalReceived = $comp->totalReceived();
            $percent = $comp->quantity_required > 0
                ? min(100, (int) round(($totalReceived / $comp->quantity_required) * 100))
                : 0;

            $deliveriesByDate = [];
            foreach ($comp->deliveries as $d) {
                $deliveriesByDate[$d->expected_date->format('Y-m-d')] = $d;
            }

            return (object) [
                'name'              => $comp->component_name,
                'product_name'      => $piItem?->product?->name ?? '—',
                'quantity_required' => $comp->quantity_required,
                'total_received'    => $totalReceived,
                'percent'           => $percent,
                'deliveries_by_date' => $deliveriesByDate,
            ];
        })->sortBy('name');
    }
@endphp

@if($hasData)
    <div class="space-y-6">
        {{-- PRODUCTION PROGRESS GRID --}}
        @if($productionDates->count() > 0)
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                        <tr>
                            <th class="px-4 py-2.5 min-w-[140px]">Product</th>
                            <th class="px-3 py-2.5 text-center">PI Qty</th>
                            @foreach($productionDates as $date)
                                <th class="px-3 py-2.5 text-center min-w-[80px]">
                                    {{ \Carbon\Carbon::parse($date)->format('d/m') }}
                                </th>
                            @endforeach
                            <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($productionRows as $row)
                            <tr class="text-gray-900 dark:text-white">
                                <td class="px-4 py-2.5 font-medium">{{ $row->product_name }}</td>
                                <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($row->pi_quantity) }}</td>
                                @foreach($productionDates as $date)
                                    @php
                                        $entry = $row->entries_by_date[$date] ?? null;
                                        $planned = $entry?->quantity ?? 0;
                                        $actual = $entry?->actual_quantity;
                                    @endphp
                                    <td class="px-3 py-2.5 text-center text-xs">
                                        @if($entry)
                                            <span class="{{ $actual !== null ? ($actual >= $planned ? 'text-green-600 dark:text-green-400 font-bold' : 'text-amber-600 dark:text-amber-400 font-bold') : 'text-gray-400' }}">
                                                {{ $actual !== null ? number_format($actual) : '—' }}</span><span class="text-gray-400">/{{ number_format($planned) }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                            <div class="h-2 rounded-full {{ $row->percent >= 100 ? 'bg-green-500' : ($row->percent > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                                 style="width: {{ $row->percent }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $row->percent }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- COMPONENTS GRID --}}
        @if($componentRows->count() > 0)
            <div>
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                    <x-heroicon-o-truck class="w-4 h-4"/>
                    Components
                </h4>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                            <tr>
                                <th class="px-4 py-2.5 min-w-[140px]">Component</th>
                                <th class="px-3 py-2.5 text-center">Needed</th>
                                @foreach($componentDates as $date)
                                    <th class="px-3 py-2.5 text-center min-w-[70px]">
                                        {{ \Carbon\Carbon::parse($date)->format('d/m') }}
                                    </th>
                                @endforeach
                                <th class="px-3 py-2.5 text-center">Received</th>
                                <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach($componentRows as $comp)
                                <tr class="text-gray-900 dark:text-white">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium">{{ $comp->name }}</div>
                                        <div class="text-xs text-gray-400">{{ $comp->product_name }}</div>
                                    </td>
                                    <td class="px-3 py-2.5 text-center font-semibold">{{ number_format($comp->quantity_required) }}</td>
                                    @foreach($componentDates as $date)
                                        @php $delivery = $comp->deliveries_by_date[$date] ?? null; @endphp
                                        <td class="px-3 py-2.5 text-center text-xs {{ $delivery?->isReceived() ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                            @if($delivery)
                                                @if($delivery->isReceived())
                                                    <span class="font-bold text-green-600 dark:text-green-400">{{ number_format($delivery->received_qty) }} ✓</span>
                                                @else
                                                    <span class="text-gray-600 dark:text-gray-300">{{ number_format($delivery->expected_qty) }}</span>
                                                @endif
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2.5 text-center font-bold {{ $comp->total_received >= $comp->quantity_required ? 'text-green-600 dark:text-green-400' : '' }}">
                                        {{ number_format($comp->total_received) }}/{{ number_format($comp->quantity_required) }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1.5">
                                            <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                                <div class="h-2 rounded-full {{ $comp->percent >= 100 ? 'bg-green-500' : ($comp->percent > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                                     style="width: {{ $comp->percent }}%"></div>
                                            </div>
                                            <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $comp->percent }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

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
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No production schedule data available for this proforma invoice.</p>
@endif
```

- [ ] **Step 2: Run the full test suite**

```bash
cd /Users/guidutra/PhpstormProjects/Impex_Main_app/.worktrees/production-schedule-redesign
php artisan test --stop-on-failure 2>&1 | tail -15
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add resources/views/portal/infolists/pi-production-progress.blade.php
git commit -m "feat: redesign client portal production progress as grid with component visibility"
```

---

## Post-Implementation Checklist

- [ ] Admin: Catalog → Products → add BOM components to a product
- [ ] Supplier Portal: create new Production Schedule for that product → verify components auto-populate with correct `quantity_required` (BOM qty × PI qty)
- [ ] Supplier Portal: add delivery dates, set expected quantities, mark some as received
- [ ] Client Portal: open PI → verify production grid shows dates as columns with actual/planned
- [ ] Client Portal: verify components section shows below production grid with delivery progress
- [ ] Client Portal: verify approval widget shows for pending_approval schedules
- [ ] Verify ⚠️ risk indicators still work in the production grid when component ETA > production date
