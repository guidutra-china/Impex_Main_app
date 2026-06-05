<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'reference' => 'PO-'.$this->faker->unique()->numerify('####'),
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'supplier_company_id' => Company::factory(),
            'contact_id' => null,
            'payment_term_id' => null,
            'status' => PurchaseOrderStatus::DRAFT->value,
            'currency_code' => 'USD',
            'incoterm' => null,
            'issue_date' => now()->toDateString(),
            'expected_delivery_date' => null,
            'notes' => null,
            'internal_notes' => null,
            'shipping_instructions' => null,
            'created_by' => null,
        ];
    }
}
