<?php

namespace App\Domain\TradeFairs\Support;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Facades\Storage;

/**
 * Serializes a fair company (with its supplier products + photos) into the
 * shape the offline PWA stores and renders. Shared by the list endpoint and
 * the upsert response so the device can reconcile server ids after a sync.
 */
class FairCompanyPresenter
{
    public static function toArray(Company $company): array
    {
        $primary = $company->contacts->firstWhere('is_primary', true)
            ?? $company->contacts->first();

        $lines = CompanyProduct::query()
            ->where('company_id', $company->id)
            ->where('role', 'supplier')
            ->with('product.images')
            ->get();

        return [
            'id' => $company->id,
            'client_uuid' => $company->client_uuid,
            'trade_fair_id' => $company->trade_fair_id,
            'name' => $company->name,
            'address_city' => $company->address_city,
            'address_country' => $company->address_country,
            'company_notes' => $company->notes,
            'category_ids' => $company->categories->pluck('id')->values()->all(),
            'contact' => $primary ? [
                'name' => $primary->name,
                'email' => $primary->email,
                'phone' => $primary->phone,
                'wechat' => $primary->wechat,
            ] : null,
            'photos' => $company->photos->map(fn ($p) => [
                'id' => $p->id,
                'url' => Storage::disk($p->disk)->url($p->path),
            ])->values()->all(),
            'products' => $lines->map(fn (CompanyProduct $cp) => [
                'company_product_id' => $cp->id,
                'client_uuid' => $cp->client_uuid,
                'name' => $cp->product?->name,
                'category_id' => $cp->product?->category_id,
                'description' => $cp->product?->description,
                'unit_price' => $cp->unit_price ? Money::toMajor($cp->unit_price) : null,
                'currency_code' => $cp->currency_code,
                'moq' => $cp->moq,
                'images' => ($cp->product?->images ?? collect())->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => Storage::disk($img->disk)->url($img->path),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
