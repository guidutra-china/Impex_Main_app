<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;

class ProductNameGenerator
{
    public static function generate(Product $product): string
    {
        if (! $product->category_id) {
            return $product->name ?? 'New Product';
        }

        $category = $product->category;

        if (! $category) {
            return $product->name ?? 'New Product';
        }

        $categoryName = $category->name;

        $requiredAttributes = $category->getAllAttributes()
            ->filter(fn ($attr) => $attr->is_required);

        if ($requiredAttributes->isEmpty()) {
            return $categoryName;
        }

        $attributeValues = $product->attributeValues()
            ->whereIn('category_attribute_id', $requiredAttributes->pluck('id'))
            ->get()
            ->keyBy('category_attribute_id');

        $parts = [$categoryName];

        foreach ($requiredAttributes as $attr) {
            $attrValue = $attributeValues->get($attr->id);

            if (! $attrValue || blank($attrValue->value)) {
                continue;
            }

            $value = $attrValue->value;

            if ($attr->unit) {
                $parts[] = $value . $attr->unit;
            } else {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    public static function updateProductName(Product $product): void
    {
        $generatedName = static::generate($product);

        if ($generatedName !== $product->name) {
            $product->updateQuietly(['name' => $generatedName]);
        }
    }
}
