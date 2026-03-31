<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleFactory extends Factory
{
    protected $model = ProductionSchedule::class;

    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'purchase_order_id'   => null,
            'supplier_company_id' => Company::factory(),
            'reference'           => 'PS-' . $this->faker->unique()->numerify('####'),
            'received_date'       => null,
            'version'             => 1,
            'notes'               => null,
            'created_by'          => null,
        ];
    }
}
