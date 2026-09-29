<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AdditionalCostScheduleSync;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Portal\Widgets\UpcomingPaymentsWidget;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Próximos Pagamentos" do portal (caso Deep Fitness, 2026-09-29): o card de
 * 30 dias contava a parcela da PI e o espelho dela no embarque; o que vencia
 * depois de 30 dias não aparecia em card nenhum; DN do cliente ficava de fora.
 * A soma dos cards tem de fechar com o total em aberto.
 */
class UpcomingPaymentsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 10:00:00');

        Filament::setCurrentPanel('portal');
        $this->company = Company::factory()->create();
        foreach (['portal:view-payments', 'portal:view-financial-summary'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo(['portal:view-payments', 'portal:view-financial-summary']);
        $this->actingAs($user);
        Filament::setTenant($this->company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $this->company->id, 'status' => 'confirmed']);
        $this->shipment = Shipment::factory()->create(['company_id' => $this->company->id]);

        // Parcela da PI que vence em 20 dias + o espelho dela no embarque.
        $this->psi(ProformaInvoice::class, $pi->id, 4_000_000, '2026-10-19', 'PI 70% — [SH / PI]');
        $this->psi(Shipment::class, $this->shipment->id, 4_000_000, '2026-10-19', 'Mirror 70% — [SH / PI]');

        // Parcela da PI que vence daqui a ~60 dias.
        $this->psi(ProformaInvoice::class, $pi->id, 2_500_000, '2026-11-28', 'PI 30%');

        // Custo do embarque cobrável do cliente (sem vencimento).
        $cost = AdditionalCost::create([
            'costable_type' => Shipment::class,
            'costable_id' => $this->shipment->id,
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Sea freight',
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 1_000_000,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => '2026-09-01',
            'status' => AdditionalCostStatus::PENDING,
        ]);
        AdditionalCostScheduleSync::syncPrimaryLeg($cost, $this->shipment);

        // DN do cliente emitida (sem vencimento).
        $dn = DebitNote::create([
            'company_id' => $this->company->id,
            'party_type' => PartyType::CLIENT,
            'total_amount' => 50_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        DebitNoteLineItem::create([
            'debit_note_id' => $dn->id,
            'description' => 'Taxi',
            'amount' => 50_000,
            'currency_code' => 'USD',
        ]);
        app(IssueDebitNoteAction::class)->execute($dn);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function psi(string $type, int $id, int $amount, ?string $due, string $label): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => $type,
            'payable_id' => $id,
            'label' => $label,
            'percentage' => 0,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_date' => $due,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 1,
        ]);
    }

    private function viewData(): array
    {
        $method = new \ReflectionMethod(UpcomingPaymentsWidget::class, 'getViewData');

        return $method->invoke(new UpcomingPaymentsWidget);
    }

    public function test_mirror_is_not_double_counted_in_next_30_days(): void
    {
        $data = $this->viewData();

        $this->assertSame(1, $data['monthCount']);
        $this->assertSame('400.00', $data['monthTotal']);
    }

    public function test_installments_due_after_30_days_get_their_own_card(): void
    {
        $data = $this->viewData();

        $this->assertSame(1, $data['laterCount']);
        $this->assertSame('250.00', $data['laterTotal']);
    }

    public function test_no_due_date_card_includes_shipment_costs_and_client_debit_notes(): void
    {
        $data = $this->viewData();

        $this->assertSame(['DN', 'Shipment'], collect($data['pending'])->pluck('doc_type')->sort()->values()->all());
        $this->assertSame('105.00', $data['pendingTotal']);
    }

    public function test_schedule_tab_hides_shipment_mirrors(): void
    {
        $mirror = PaymentScheduleItem::where('label', 'like', 'Mirror%')->firstOrFail();
        $cost = PaymentScheduleItem::where('payable_type', Shipment::class)
            ->where('source_type', AdditionalCost::class)->firstOrFail();
        $dn = PaymentScheduleItem::where('payable_type', DebitNote::class)->firstOrFail();

        Livewire::test(ListPayments::class)
            ->call('switchTab', 'schedule')
            ->assertCanSeeTableRecords([$cost, $dn])
            ->assertCanNotSeeTableRecords([$mirror])
            // A ordenação por referência faz join com shipments/proforma_invoices.
            ->sortTable('payable_ref')
            ->assertCanSeeTableRecords([$cost, $dn])
            ->searchTable($dn->payable->reference)
            ->assertCanSeeTableRecords([$dn]);
    }
}
