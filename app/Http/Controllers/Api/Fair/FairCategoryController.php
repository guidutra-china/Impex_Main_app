<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\Catalog\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FairCategoryController
{
    /**
     * Quick-add a product category from the fair PWA. Online-only: the new
     * category must exist on the server before a product can reference it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);

        // Reuse a same-named category (case-insensitive) instead of duplicating.
        $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($category === null) {
            $category = Category::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'is_active' => true,
            ]);
        }

        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
        ], 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $i = 2;
        while (Category::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
