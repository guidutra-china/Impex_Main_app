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
            ['code' => 'BR', 'name' => 'Brasil'],
            ['code' => 'US', 'name' => 'Estados Unidos'],
            ['code' => 'IN', 'name' => 'Índia'],
            ['code' => 'VN', 'name' => 'Vietnã'],
            ['code' => 'TR', 'name' => 'Turquia'],
            ['code' => 'DE', 'name' => 'Alemanha'],
            ['code' => 'IT', 'name' => 'Itália'],
            ['code' => 'JP', 'name' => 'Japão'],
            ['code' => 'KR', 'name' => 'Coreia do Sul'],
            ['code' => 'TH', 'name' => 'Tailândia'],
            ['code' => 'MX', 'name' => 'México'],
            ['code' => 'AR', 'name' => 'Argentina'],
            ['code' => 'CL', 'name' => 'Chile'],
            ['code' => 'CO', 'name' => 'Colômbia'],
            ['code' => 'PE', 'name' => 'Peru'],
        ];
    }
}
