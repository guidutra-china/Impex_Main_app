<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class InquiryBulkAddProductsTest extends TestCase
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

    public function test_bulk_add_creates_items_with_default_quantity_and_sequential_sort_order(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        // Existing item so sort_order must continue from its value.
        $existingProduct = Product::factory()->create(['name' => 'Existing Widget']);
        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $existingProduct->id,
            'quantity' => 7, 'unit' => 'pcs', 'sort_order' => 5,
        ]);

        $products = collect([
            Product::factory()->create(['name' => 'Street Light 26W']),
            Product::factory()->create(['name' => 'Street Light 30W']),
            Product::factory()->create(['name' => 'Street Light 40W']),
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $inquiry,
            'pageClass' => EditInquiry::class,
        ])
            ->callTableAction('bulkAddProducts', data: [
                'product_ids' => $products->pluck('id')->all(),
            ]);

        $this->assertSame(4, $inquiry->items()->count());

        $newItems = $inquiry->items()->where('sort_order', '>', 5)->orderBy('sort_order')->get();
        $this->assertSame([6, 7, 8], $newItems->pluck('sort_order')->all());

        foreach ($newItems as $item) {
            $this->assertSame(1, $item->quantity);
            $this->assertSame('pcs', $item->unit);
            $this->assertSame($item->product->name, $item->description);
        }
    }

    public function test_bulk_add_skips_products_already_in_the_inquiry(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $existing = Product::factory()->create(['name' => 'Already There']);
        $fresh = Product::factory()->create(['name' => 'New Product']);

        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $existing->id,
            'quantity' => 10, 'unit' => 'pcs', 'sort_order' => 1,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $inquiry,
            'pageClass' => EditInquiry::class,
        ])
            ->callTableAction('bulkAddProducts', data: [
                'product_ids' => [$existing->id, $fresh->id],
            ]);

        $this->assertSame(2, $inquiry->items()->count());
        $this->assertSame(1, $inquiry->items()->where('product_id', $existing->id)->count());
        $this->assertSame(10, $inquiry->items()->where('product_id', $existing->id)->first()->quantity);
        $this->assertSame(1, $inquiry->items()->where('product_id', $fresh->id)->first()->quantity);
    }

    public function test_options_filter_by_category_and_prioritize_client_products(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $lights = Category::create(['name' => 'Lights', 'slug' => 'lights', 'is_active' => true]);
        $poles = Category::create(['name' => 'Poles', 'slug' => 'poles', 'is_active' => true]);

        // Explicit SKUs: the auto-generator uses MySQL-only SQL that breaks on the sqlite test DB.
        $light = Product::factory()->create(['name' => 'AAA Light', 'sku' => 'LGT-00001', 'category_id' => $lights->id]);
        $clientLight = Product::factory()->create(['name' => 'ZZZ Client Light', 'sku' => 'LGT-00002', 'category_id' => $lights->id]);
        $clientLight->companies()->attach($client->id, ['role' => 'client']);
        $pole = Product::factory()->create(['name' => 'Steel Pole', 'sku' => 'POL-00001', 'category_id' => $poles->id]);

        $manager = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $inquiry,
            'pageClass' => EditInquiry::class,
        ])->instance();

        $options = (new ReflectionMethod($manager, 'bulkProductOptions'))
            ->invoke($manager, $lights->id, null);

        $this->assertArrayHasKey($light->id, $options);
        $this->assertArrayHasKey($clientLight->id, $options);
        $this->assertArrayNotHasKey($pole->id, $options);

        // Client product (★) is listed first despite sorting after alphabetically.
        $this->assertSame($clientLight->id, array_key_first($options));
        $this->assertStringContainsString('★', $options[$clientLight->id]);
        $this->assertStringNotContainsString('★', $options[$light->id]);
    }
}
