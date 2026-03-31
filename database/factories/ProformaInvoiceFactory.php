<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceFactory extends Factory
{
    protected $model = ProformaInvoice::class;

    public function definition(): array
    {
        return [
            'reference'           => 'PI-' . $this->faker->unique()->numerify('####'),
            'client_reference'    => null,
            'inquiry_id'          => null,
            'company_id'          => Company::factory(),
            'contact_id'          => null,
            'payment_term_id'     => null,
            'status'              => 'draft',
            'currency_code'       => 'USD',
            'incoterm'            => null,
            'issue_date'          => now()->toDateString(),
            'valid_until'         => now()->addDays(30)->toDateString(),
            'validity_days'       => 30,
            'notes'               => null,
            'internal_notes'      => null,
            'created_by'          => null,
            'responsible_user_id' => null,
        ];
    }
}
