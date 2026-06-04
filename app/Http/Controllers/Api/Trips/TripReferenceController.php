<?php

namespace App\Http\Controllers\Api\Trips;

use App\Domain\Settings\Models\Currency;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use Illuminate\Http\JsonResponse;

class TripReferenceController
{
    public function index(): JsonResponse
    {
        // Labels follow the request locale (SetLocale middleware honours the
        // authenticated user's `locale`), consistent with the rest of the app.
        $categories = collect(TravelExpenseCategory::cases())
            ->map(fn (TravelExpenseCategory $c) => [
                'value' => $c->value,
                'label' => $c->getLabel(),
            ])
            ->all();

        $currencies = Currency::query()->orderBy('code')->pluck('code')->all();
        if (empty($currencies)) {
            $currencies = ['CNY', 'USD', 'BRL'];
        }

        // CNY is the default trip-expense currency, so it leads the list (also
        // makes it the <select>'s first/fallback option on the mobile app).
        if (in_array('CNY', $currencies, true)) {
            $currencies = array_values(array_merge(['CNY'], array_diff($currencies, ['CNY'])));
        }

        return response()->json([
            'expense_categories' => $categories,
            'currencies' => $currencies,
            'countries' => $this->countries(),
        ]);
    }

    /**
     * Common trip-destination countries for the mobile picker. The picker
     * should still allow free-text ISO codes so any country is reachable.
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
        ];
    }
}
