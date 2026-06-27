<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\OpenFinancialItemsTool;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OpenFinancialItemsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-payments', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-payments');

        $tool = new OpenFinancialItemsTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_receivables_includes_open_pi_installment(): void
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'USD']);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'amount' => 500000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_credit' => false,
        ]);

        $result = (new OpenFinancialItemsTool)->run(['tipo' => 'receber'], User::factory()->create());

        $this->assertSame('receber', $result['tipo']);
        $this->assertGreaterThanOrEqual(1, $result['titulos_em_aberto']);
        $this->assertArrayHasKey('USD', $result['totais_por_moeda']);
        $this->assertIsString($result['totais_por_moeda']['USD']);
    }
}
