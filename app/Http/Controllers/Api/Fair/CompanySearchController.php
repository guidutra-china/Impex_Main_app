<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySearchController
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $matches = Company::query()
            ->where('name', 'like', '%'.$data['q'].'%')
            ->where(function ($q) {
                $q->whereHas('companyRoles', fn ($r) => $r->where('role', CompanyRole::SUPPLIER->value))
                    ->orWhereNotNull('trade_fair_id');
            })
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'address_city', 'address_country', 'trade_fair_id']);

        return response()->json([
            'data' => $matches->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => $c->address_city,
                'country' => $c->address_country,
                'trade_fair_id' => $c->trade_fair_id,
            ])->all(),
        ]);
    }
}
