<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\TradeFairs\Models\TradeFair;
use Illuminate\Http\JsonResponse;

class TradeFairController
{
    public function active(): JsonResponse
    {
        $fairs = TradeFair::query()
            ->where('end_date', '>=', now()->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'name', 'location', 'start_date', 'end_date']);

        return response()->json([
            'data' => $fairs->map(fn (TradeFair $fair) => [
                'id' => $fair->id,
                'name' => $fair->name,
                'location' => $fair->location,
                'start_date' => $fair->start_date?->toDateString(),
                'end_date' => $fair->end_date?->toDateString(),
            ])->all(),
        ]);
    }
}
