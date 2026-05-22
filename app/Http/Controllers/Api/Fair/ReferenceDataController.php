<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\Catalog\Models\Category;
use Illuminate\Http\JsonResponse;

class ReferenceDataController
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return response()->json([
            'categories' => $categories,
            'currencies' => ['USD', 'CNY', 'EUR', 'GBP'],
            'countries' => $this->countries(),
        ]);
    }

    /**
     * Common trading-partner countries shipped to the mobile client for the
     * country picker. Not exhaustive — the picker should allow free-text ISO
     * codes as well so any country is reachable.
     *
     * @return array<int, array{code: string, name: string}>
     */
    private function countries(): array
    {
        return [
            ['code' => 'CN', 'name' => 'China'],
            ['code' => 'BR', 'name' => 'Brazil'],
            ['code' => 'US', 'name' => 'United States'],
            ['code' => 'IN', 'name' => 'India'],
            ['code' => 'VN', 'name' => 'Vietnam'],
            ['code' => 'TR', 'name' => 'Turkey'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'IT', 'name' => 'Italy'],
            ['code' => 'JP', 'name' => 'Japan'],
            ['code' => 'KR', 'name' => 'South Korea'],
            ['code' => 'TH', 'name' => 'Thailand'],
            ['code' => 'MX', 'name' => 'Mexico'],
            ['code' => 'AR', 'name' => 'Argentina'],
            ['code' => 'CL', 'name' => 'Chile'],
            ['code' => 'CO', 'name' => 'Colombia'],
            ['code' => 'PE', 'name' => 'Peru'],
        ];
    }
}
