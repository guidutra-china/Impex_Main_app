<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierQuotation>
 */
class SupplierQuotationFactory extends Factory
{
    protected $model = SupplierQuotation::class;

    public function definition(): array
    {
        return [
            'reference' => 'SQ-'.$this->faker->unique()->numerify('####'),
            'inquiry_id' => Inquiry::factory(),
            'company_id' => Company::factory(),
            'contact_id' => null,
            'status' => SupplierQuotationStatus::REQUESTED->value,
            'currency_code' => 'USD',
            'supplier_reference' => null,
            'requested_at' => now()->toDateString(),
            'received_at' => null,
            'valid_until' => null,
            'lead_time_days' => null,
            'moq' => null,
            'incoterm' => null,
            'payment_term_id' => null,
            'notes' => null,
            'internal_notes' => null,
            'rfq_instructions' => null,
            'created_by' => null,
        ];
    }
}
