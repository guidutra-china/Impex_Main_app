<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Models\TradeFair;
use App\Domain\TradeFairs\Support\FairCompanyPresenter;
use Illuminate\Http\JsonResponse;

class FairCompanyController
{
    /**
     * All companies registered for a fair (by any device/user), with their
     * supplier products and photos. Feeds the offline list the device caches.
     */
    public function index(TradeFair $tradeFair): JsonResponse
    {
        $companies = Company::query()
            ->where('trade_fair_id', $tradeFair->id)
            ->with(['photos', 'contacts'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $companies->map(fn (Company $c) => FairCompanyPresenter::toArray($c))->all(),
        ]);
    }
}
