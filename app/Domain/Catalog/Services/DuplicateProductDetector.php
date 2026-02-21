<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Collection;

class DuplicateProductDetector
{
    /**
     * Find existing products in the same category that share similar required attribute values.
     *
     * @param int $categoryId
     * @param array<int, string> $attributeValues [category_attribute_id => value]
     * @param int|null $excludeProductId Product to exclude (for edit scenarios)
     * @return Collection<Product>
     */
    public static function findSimilar(
        int $categoryId,
        array $attributeValues,
        ?int $excludeProductId = null,
    ): Collection {
        $category = Category::find($categoryId);

        if (! $category) {
            return collect();
        }

        $requiredAttributeIds = $category->getAllAttributes()
            ->filter(fn ($attr) => $attr->is_required)
            ->pluck('id')
            ->toArray();

        $relevantValues = collect($attributeValues)
            ->filter(fn ($value, $attrId) => in_array((int) $attrId, $requiredAttributeIds) && filled($value));

        if ($relevantValues->isEmpty()) {
            return collect();
        }

        $query = Product::query()
            ->where('category_id', $categoryId)
            ->whereNull('parent_id');

        if ($excludeProductId) {
            $query->where('id', '!=', $excludeProductId);
        }

        foreach ($relevantValues as $attrId => $value) {
            $query->whereHas('attributeValues', function ($q) use ($attrId, $value) {
                $q->where('category_attribute_id', $attrId)
                    ->where('value', $value);
            });
        }

        return $query->with('attributeValues.categoryAttribute')->get();
    }

    /**
     * Build a map of [category_attribute_id => value] from a product's current attribute values.
     */
    public static function getAttributeMap(Product $product): array
    {
        return $product->attributeValues()
            ->whereHas('categoryAttribute', fn ($q) => $q->where('is_required', true))
            ->pluck('value', 'category_attribute_id')
            ->toArray();
    }
}
