<?php

namespace App\Http\Requests\Api\Trips;

use App\Domain\Travel\Enums\TravelExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth + role enforced by middleware
    }

    protected function prepareForValidation(): void
    {
        // Multipart sends booleans as strings ("1"/"0"/"true"); normalize so the
        // boolean rule and the XOR check below behave consistently.
        $this->merge([
            'is_internal' => $this->boolean('is_internal'),
            'finalize' => $this->boolean('finalize'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],

            'title' => ['required', 'string', 'max:255'],
            'is_internal' => ['nullable', 'boolean'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],

            'destination_city' => ['nullable', 'string', 'max:120'],
            'destination_country' => ['nullable', 'string', 'size:2'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'finalize' => ['nullable', 'boolean'],

            'expenses' => ['nullable', 'array', 'max:200'],
            'expenses.*.client_uuid' => ['nullable', 'uuid'],
            'expenses.*.category' => ['required', Rule::enum(TravelExpenseCategory::class)],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            'expenses.*.amount' => ['required', 'numeric', 'min:0'],
            'expenses.*.currency_code' => ['required', 'string', 'size:3'],
            'expenses.*.expense_date' => ['required', 'date'],
            'expenses.*.photos' => ['nullable', 'array', 'max:8'],
            'expenses.*.photos.*' => ['nullable', 'file', 'image', 'max:10240'],

            'deleted_expense_uuids' => ['nullable', 'array'],
            'deleted_expense_uuids.*' => ['uuid'],
        ];
    }

    public function withValidator($validator): void
    {
        // The trip must belong to a company OR be internal — never neither.
        $validator->after(function ($validator) {
            if (! $this->boolean('is_internal') && empty($this->input('company_id'))) {
                $validator->errors()->add(
                    'company_id',
                    'A viagem precisa estar vinculada a uma empresa ou ser marcada como interna.'
                );
            }
        });
    }
}
