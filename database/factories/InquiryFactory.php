<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

class InquiryFactory extends Factory
{
    protected $model = Inquiry::class;

    public function definition(): array
    {
        return [
            'reference'    => 'INQ-' . $this->faker->unique()->numerify('####'),
            'company_id'   => Company::factory(),
            'status'       => 'received',
            'source'       => 'email',
            'currency_code' => 'USD',
            'received_at'  => now()->toDateString(),
        ];
    }
}
