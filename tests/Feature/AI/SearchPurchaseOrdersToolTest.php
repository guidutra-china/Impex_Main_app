<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\SearchPurchaseOrdersTool;
use App\Domain\CRM\Models\Company;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SearchPurchaseOrdersToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-purchase-orders', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-purchase-orders');

        $tool = new SearchPurchaseOrdersTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_run_returns_supplier_and_formatted_total(): void
    {
        $supplier = Company::factory()->create(['name' => 'Shenzhen Parts Co']);
        PurchaseOrder::factory()->create([
            'reference' => 'PO-555',
            'supplier_company_id' => $supplier->id,
            'currency_code' => 'CNY',
        ]);

        $result = (new SearchPurchaseOrdersTool)->run(['referencia' => '555'], User::factory()->create());

        $this->assertSame(1, $result['count']);
        $this->assertSame('PO-555', $result['purchase_orders'][0]['reference']);
        $this->assertSame('Shenzhen Parts Co', $result['purchase_orders'][0]['fornecedor']);
        $this->assertSame('CNY 0.00', $result['purchase_orders'][0]['total']);
    }
}
