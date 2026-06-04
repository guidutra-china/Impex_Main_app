<?php

namespace App\Http\Controllers\Api\Trips;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySearchController
{
    /**
     * Search companies a trip can be linked to. Unlike the Fair search this is
     * not restricted to suppliers — a trip may belong to a client, supplier or
     * forwarder.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $matches = Company::query()
            ->with('companyRoles')
            ->where('name', 'like', '%'.$data['q'].'%')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'address_city', 'address_country']);

        return response()->json([
            'data' => $matches->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => $c->address_city,
                'country' => $c->address_country,
                'roles' => $c->companyRoles
                    ->pluck('role')
                    ->map(fn (CompanyRole $role) => $role->value)
                    ->all(),
            ])->all(),
        ]);
    }
}
