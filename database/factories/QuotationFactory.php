<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'reference' => 'QT-'.$this->faker->unique()->numerify('####'),
            'inquiry_id' => Inquiry::factory(),
            'company_id' => Company::factory(),
            'contact_id' => null,
            'payment_term_id' => null,
            'status' => QuotationStatus::DRAFT->value,
            'version' => 1,
            'currency_code' => 'USD',
            'commission_type' => 'embedded',
            'commission_rate' => 0,
            'show_suppliers' => false,
            'validity_days' => 30,
            'valid_until' => now()->addDays(30)->toDateString(),
            'notes' => null,
            'internal_notes' => null,
            'created_by' => null,
        ];
    }
}
