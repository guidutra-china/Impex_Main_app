<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use App\Domain\Planning\Models\ComponentDelivery;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use Database\Factories\ProductionScheduleComponentFactory;
use Database\Factories\ProductionScheduleEntryFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Supplier Portal grids upsert cells via updateOrCreate; these unique
 * indexes are the database backstop so concurrent saves cannot insert two rows
 * for the same cell.
 */
class SupplierGridUniqueConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_production_schedule_entry_cell_is_rejected_by_the_database(): void
    {
        $entry = ProductionScheduleEntryFactory::new()->create(['production_date' => '2026-08-05']);

        $this->expectException(QueryException::class);

        ProductionScheduleEntry::create([
            'production_schedule_id' => $entry->production_schedule_id,
            'proforma_invoice_item_id' => $entry->proforma_invoice_item_id,
            'production_date' => '2026-08-05',
            'quantity' => 10,
        ]);
    }

    public function test_update_or_create_still_updates_the_existing_entry_cell(): void
    {
        $entry = ProductionScheduleEntryFactory::new()->create(['quantity' => 5, 'production_date' => '2026-08-05']);

        ProductionScheduleEntry::updateOrCreate(
            [
                'production_schedule_id' => $entry->production_schedule_id,
                'proforma_invoice_item_id' => $entry->proforma_invoice_item_id,
                // Carbon key: sqlite stores the casted date with a time part,
                // so a plain 'Y-m-d' string would not match (MySQL DATE does).
                'production_date' => \Illuminate\Support\Carbon::parse('2026-08-05'),
            ],
            ['quantity' => 99],
        );

        $this->assertSame(1, ProductionScheduleEntry::count());
        $this->assertSame(99, $entry->fresh()->quantity);
    }

    public function test_duplicate_component_delivery_cell_is_rejected_by_the_database(): void
    {
        $component = ProductionScheduleComponentFactory::new()->create();

        ComponentDelivery::create([
            'production_schedule_component_id' => $component->id,
            'expected_date' => '2026-08-01',
            'expected_qty' => 10,
        ]);

        $this->expectException(QueryException::class);

        ComponentDelivery::create([
            'production_schedule_component_id' => $component->id,
            'expected_date' => '2026-08-01',
            'expected_qty' => 20,
        ]);
    }

    public function test_update_or_create_still_updates_the_existing_delivery_cell(): void
    {
        $component = ProductionScheduleComponentFactory::new()->create();

        ComponentDelivery::create([
            'production_schedule_component_id' => $component->id,
            'expected_date' => '2026-08-01',
            'expected_qty' => 10,
        ]);

        ComponentDelivery::updateOrCreate(
            [
                'production_schedule_component_id' => $component->id,
                // Carbon key: sqlite stores the casted date with a time part,
                // so a plain 'Y-m-d' string would not match (MySQL DATE does).
                'expected_date' => \Illuminate\Support\Carbon::parse('2026-08-01'),
            ],
            ['expected_qty' => 25],
        );

        $this->assertSame(1, ComponentDelivery::count());
        $this->assertSame(25, ComponentDelivery::first()->expected_qty);
    }
}
