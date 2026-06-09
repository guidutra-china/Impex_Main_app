<?php

namespace App\Http\Requests\Api\Fair;

use App\Domain\TradeFairs\Models\TradeFair;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFairSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth + role enforced by middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trade_fair_id' => ['required', 'integer', Rule::exists((new TradeFair)->getTable(), 'id')],

            // Offline-sync identity: client_uuid for device-created companies,
            // id when editing a company fetched from the server.
            'id' => ['nullable', 'integer', 'exists:companies,id'],
            'client_uuid' => ['nullable', 'uuid'],
            'existing_company_id' => ['nullable', 'integer', 'exists:companies,id'],

            // A name is only required when creating a brand-new company.
            'company_name' => ['required_without_all:existing_company_id,id', 'nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'company_notes' => ['nullable', 'string', 'max:5000'],

            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],

            // Contact is fully optional — a company can be registered without one.
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_wechat' => ['nullable', 'string', 'max:120'],

            'company_photos' => ['nullable', 'array', 'max:8'],
            'company_photos.*' => ['nullable', 'file', 'image', 'max:10240'],
            // Legacy single-photo field — kept so already-queued offline payloads still sync.
            'business_card_photo' => ['nullable', 'file', 'image', 'max:10240'],

            'products' => ['nullable', 'array', 'max:50'],
            'products.*.id' => ['nullable', 'integer', 'exists:company_product,id'],
            'products.*.client_uuid' => ['nullable', 'uuid'],
            'products.*.name' => ['required', 'string', 'max:255'],
            'products.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'products.*.description' => ['nullable', 'string', 'max:5000'],
            'products.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'products.*.currency_code' => ['nullable', 'string', 'size:3'],
            'products.*.moq' => ['nullable', 'integer', 'min:0'],
            'products.*.photos' => ['nullable', 'array', 'max:8'],
            'products.*.photos.*' => ['nullable', 'file', 'image', 'max:10240'],
            // Legacy single-photo field — kept so already-queued offline payloads still sync.
            'products.*.photo' => ['nullable', 'file', 'image', 'max:10240'],
            'products.*.deleted_image_ids' => ['nullable', 'array'],
            'products.*.deleted_image_ids.*' => ['integer'],

            'deleted_product_uuids' => ['nullable', 'array'],
            'deleted_product_uuids.*' => ['uuid'],
            'deleted_product_ids' => ['nullable', 'array'],
            'deleted_product_ids.*' => ['integer'],
        ];
    }
}
