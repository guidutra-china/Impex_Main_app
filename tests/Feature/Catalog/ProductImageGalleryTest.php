<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SyncProductPrimaryImageAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Gallery Test Product',
            'status' => 'draft',
        ]);
    }

    public function test_sync_action_mirrors_first_image_into_avatar(): void
    {
        $product = $this->makeProduct();

        $product->images()->create(['disk' => 'public', 'path' => 'products/a.jpg', 'sort_order' => 0, 'is_primary' => false]);
        $product->images()->create(['disk' => 'public', 'path' => 'products/b.jpg', 'sort_order' => 1, 'is_primary' => false]);

        app(SyncProductPrimaryImageAction::class)->execute($product);

        $product->refresh();

        $this->assertSame('products/a.jpg', $product->avatar);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertTrue($product->images()->where('path', 'products/a.jpg')->value('is_primary'));
    }

    public function test_sync_action_honours_preferred_primary(): void
    {
        $product = $this->makeProduct();

        $first = $product->images()->create(['disk' => 'public', 'path' => 'products/a.jpg', 'sort_order' => 0, 'is_primary' => true]);
        $second = $product->images()->create(['disk' => 'public', 'path' => 'products/b.jpg', 'sort_order' => 1, 'is_primary' => false]);

        app(SyncProductPrimaryImageAction::class)->execute($product, $second->id);

        $product->refresh();

        $this->assertSame('products/b.jpg', $product->avatar);
        $this->assertTrue($second->refresh()->is_primary);
        $this->assertFalse($first->refresh()->is_primary);
    }

    public function test_clearing_gallery_clears_avatar(): void
    {
        $product = $this->makeProduct();
        $product->images()->create(['disk' => 'public', 'path' => 'products/a.jpg', 'sort_order' => 0, 'is_primary' => true]);
        app(SyncProductPrimaryImageAction::class)->execute($product);
        $this->assertSame('products/a.jpg', $product->fresh()->avatar);

        $product->images()->get()->each->delete();
        app(SyncProductPrimaryImageAction::class)->execute($product);

        $this->assertNull($product->fresh()->avatar);
    }

    public function test_orphan_guard_keeps_file_referenced_by_gallery_when_avatar_changes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/shared.jpg', 'x');
        Storage::disk('public')->put('products/new.jpg', 'y');

        $product = $this->makeProduct();
        $product->update(['avatar' => 'products/shared.jpg']);

        // A gallery row also owns the same file.
        $product->images()->create(['disk' => 'public', 'path' => 'products/shared.jpg', 'sort_order' => 0, 'is_primary' => true]);

        // Changing the avatar must NOT delete a file the gallery still references.
        $product->update(['avatar' => 'products/new.jpg']);

        Storage::disk('public')->assertExists('products/shared.jpg');
    }

    public function test_deleting_image_removes_unreferenced_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/solo.jpg', 'x');

        $product = $this->makeProduct();
        $image = $product->images()->create(['disk' => 'public', 'path' => 'products/solo.jpg', 'sort_order' => 0, 'is_primary' => false]);

        $image->delete();

        Storage::disk('public')->assertMissing('products/solo.jpg');
    }

    public function test_deleting_image_keeps_file_still_used_by_avatar(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/keep.jpg', 'x');

        $product = $this->makeProduct();
        $product->update(['avatar' => 'products/keep.jpg']);
        $image = $product->images()->create(['disk' => 'public', 'path' => 'products/keep.jpg', 'sort_order' => 0, 'is_primary' => true]);

        $image->delete();

        Storage::disk('public')->assertExists('products/keep.jpg');
    }
}
