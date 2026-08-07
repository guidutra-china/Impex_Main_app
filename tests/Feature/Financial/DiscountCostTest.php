<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountCostTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->supplier = Company::create(['name' => 'Shenzhen Maker', 'status' => 'active']);
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
    }

    public function test_discount_enum_case_is_complete(): void
    {
        $type = AdditionalCostType::DISCOUNT;

        $this->assertSame('discount', $type->value);
        $this->assertSame('Discount', $type->getEnglishLabel());
        $this->assertSame('warning', $type->getColor());
        $this->assertNotNull($type->getIcon());
        $this->assertSame('Desconto', __('enums.additional_cost_type.discount', [], 'pt_BR'));
        $this->assertSame('Discount', __('enums.additional_cost_type.discount', [], 'en'));
    }
}
