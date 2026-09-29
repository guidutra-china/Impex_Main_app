<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AdditionalCostScheduleSync;
use App\Domain\Logistics\Models\Shipment;
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

    /**
     * Deep Fitness 2026-09-29: o card "Valor Total a Pagar" soma PI, custos do
     * embarque e DNs, e Total − Pago tem de dar o Saldo Pendente. Espelho de
     * Shipment e parcela waived não entram.
     */
    public function test_total_billed_minus_paid_equals_pending_balance(): void
    {
        Filament::setCurrentPanel('portal');
        Permission::firstOrCreate(['name' => 'portal:view-financial-summary', 'guard_name' => 'web']);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('portal:view-financial-summary');
        $this->actingAs($user);
        Filament::setTenant($company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id, 'status' => 'confirmed']);
        $shipment = Shipment::factory()->create(['company_id' => $company->id]);

        $psi = fn (string $type, int $id, int $amount, PaymentScheduleStatus $status, string $label) => PaymentScheduleItem::create([
            'payable_type' => $type,
            'payable_id' => $id,
            'label' => $label,
            'percentage' => 0,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => $status,
            'sort_order' => 1,
        ]);

        // PI 1.000,00 com 300,00 pagos; espelho no embarque (fora); waived 88,00 (fora).
        $piItem = $psi(ProformaInvoice::class, $pi->id, 10_000_000, PaymentScheduleStatus::PENDING, 'PI 100%');
        $psi(Shipment::class, $shipment->id, 10_000_000, PaymentScheduleStatus::PENDING, 'Mirror 100%');
        $psi(ProformaInvoice::class, $pi->id, 880_000, PaymentScheduleStatus::WAIVED, 'Waived');

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $company->id,
            'amount' => 3_000_000,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $piItem->id,
            'allocated_amount' => 3_000_000,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => 3_000_000,
        ]);

        // Frete do embarque cobrável do cliente: 200,00.
        $cost = AdditionalCost::create([
            'costable_type' => Shipment::class,
            'costable_id' => $shipment->id,
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Sea freight',
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => '2026-09-01',
            'status' => AdditionalCostStatus::PENDING,
        ]);
        AdditionalCostScheduleSync::syncPrimaryLeg($cost, $shipment);

        // DN do cliente: 50,00.
        $dn = DebitNote::create([
            'company_id' => $company->id,
            'party_type' => PartyType::CLIENT,
            'total_amount' => 500_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        DebitNoteLineItem::create([
            'debit_note_id' => $dn->id,
            'description' => 'Taxi',
            'amount' => 500_000,
            'currency_code' => 'USD',
        ]);
        app(IssueDebitNoteAction::class)->execute($dn);

        // Total 1.250,00 − pago 300,00 = pendente 950,00.
        $stats = collect((new \ReflectionMethod(FinancialSummaryWidget::class, 'getStats'))->invoke(new FinancialSummaryWidget))
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()]);

        $this->assertSame('USD 1,250.00', $stats[__('widgets.portal.total_billed')]);
        $this->assertSame('USD 300.00', $stats[__('widgets.portal.total_paid')]);
        $this->assertSame('USD 950.00', $stats[__('widgets.portal.pending_balance')]);
    }
}
