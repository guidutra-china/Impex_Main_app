<?php

namespace App\Filament\Resources\Catalog\Products\Concerns;

use App\Domain\Catalog\Actions\SyncProductPrimaryImageAction;
use App\Domain\Catalog\Models\Product;

trait ManagesProductGallery
{
    /**
     * Seed the gallery FileUpload state from the product_images relation
     * (falls back to the legacy avatar when no rows exist yet).
     */
    protected function fillGalleryImages(array $data): array
    {
        $record = $this->getRecord();

        $paths = $record->images()->orderBy('sort_order')->orderBy('id')->pluck('path')->all();

        if (empty($paths) && $record->avatar) {
            $paths = [$record->avatar];
        }

        $data['gallery_images'] = $paths;

        return $data;
    }

    /**
     * Persist the gallery FileUpload state into product_images and mirror the
     * primary (first) image into Product.avatar.
     */
    protected function syncProductGallery(Product $product): void
    {
        $paths = array_values(array_filter(
            (array) ($this->data['gallery_images'] ?? []),
            fn ($path) => filled($path),
        ));

        foreach ($paths as $index => $path) {
            $product->images()->updateOrCreate(
                ['path' => $path],
                ['sort_order' => $index, 'disk' => 'public', 'is_primary' => $index === 0],
            );
        }

        // Remove rows no longer present — fetch models so the deleting hook
        // fires per-row file cleanup (mass delete would bypass it).
        $product->images()
            ->when($paths !== [], fn ($query) => $query->whereNotIn('path', $paths))
            ->get()
            ->each
            ->delete();

        $primaryId = $paths !== []
            ? $product->images()->where('path', $paths[0])->value('id')
            : null;

        app(SyncProductPrimaryImageAction::class)->execute($product, $primaryId);
    }
}
