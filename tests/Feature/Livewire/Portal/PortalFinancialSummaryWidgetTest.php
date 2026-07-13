<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Widgets\FinancialSummaryWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * O "Saldo Pendente" do painel do portal deve fechar com o Contas a Pagar:
 * saldo RESTANTE das parcelas abertas (pending + due + overdue), sem PI
 * cancelada, sem crédito e sem parcelas waived.
 */
class PortalFinancialSummaryWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_balance_uses_remaining_of_open_installments_and_skips_cancelled(): void
    {
        Filament::setCurrentPanel('portal');
        Permission::firstOrCreate(['name' => 'portal:view-financial-summary', 'guard_name' => 'web']);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('portal:view-financial-summary');
        $this->actingAs($user);
        Filament::setTenant($company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id, 'status' => 'confirmed']);

        $makePsi = fn (ProformaInvoice $target, string $status, int $amount, int $sort) => PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $target->id,
            'label' => 'Parcela '.$sort,
            'percentage' => 0,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => $status,
            'sort_order' => $sort,
        ]);

        // DUE parcialmente paga: 400,00 com 150,00 pagos → saldo 250,00.
        $due = $makePsi($pi, PaymentScheduleStatus::DUE->value, 4_000_000, 1);
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $company->id,
            'amount' => 1_500_000,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $due->id,
            'allocated_amount' => 1_500_000,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => 1_500_000,
        ]);

        // PENDING 100,00 conta inteira; WAIVED 999,00 fica fora.
        $makePsi($pi, PaymentScheduleStatus::PENDING->value, 1_000_000, 2);
        $makePsi($pi, PaymentScheduleStatus::WAIVED->value, 9_990_000, 3);

        // PI cancelada: parcela aberta de 777,00 não pode entrar.
        $cancelled = ProformaInvoice::factory()->create(['company_id' => $company->id, 'status' => 'cancelled']);
        $makePsi($cancelled, PaymentScheduleStatus::PENDING->value, 7_770_000, 1);

        // Esperado: 250,00 (saldo da due) + 100,00 (pending) = 350,00; pago 150,00.
        Livewire::test(FinancialSummaryWidget::class)
            ->assertSuccessful()
            ->assertSee('350.00')
            ->assertSee('150.00')
            ->assertDontSee('999')
            ->assertDontSee('777');
    }
}
