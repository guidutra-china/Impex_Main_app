<?php

namespace Tests\Feature\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use App\Filament\Portal\Widgets\ProformaInvoicesListStats;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PI-2026-00070 / PI-2026-00083: o desconto já aplicado numa parcela entra no
 * paid_amount dela. O resumo do Portal também abatia a parcela de crédito do
 * total devido, contando o mesmo desconto duas vezes e mostrando um saldo
 * menor que a dívida real (a comissão ainda em aberto).
 */
class PortalPiAppliedCreditSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
    }

    private function stage(int $amount, array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE,
            'sort_order' => $this->pi->paymentScheduleItems()->count() + 1,
        ], $overrides));
    }

    private function cost(int $amount, AdditionalCostType $type = AdditionalCostType::COMMISSION): AdditionalCost
    {
        return AdditionalCost::create([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $this->pi->id,
            'cost_type' => $type,
            'description' => 'Cost '.$type->value,
            'amount' => $amount,
            'currency_code' => 'USD',
            'amount_in_document_currency' => $amount,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => '2026-08-20',
            'status' => AdditionalCostStatus::PENDING,
        ]);
    }

    /** Parcela do lado do cliente gerada por um custo adicional. */
    private function costStage(AdditionalCost $cost): PaymentScheduleItem
    {
        return $this->stage(abs($cost->amount), [
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Cost: '.$cost->description,
            'is_credit' => $cost->amount < 0,
        ]);
    }

    private function payment(int $amount): Payment
    {
        return Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
    }

    private function allocate(PaymentScheduleItem $psi, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $this->payment($amount)->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function applyCredit(PaymentScheduleItem $target, PaymentScheduleItem $credit, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $this->payment(0)->id,
            'payment_schedule_item_id' => $target->id,
            'credit_schedule_item_id' => $credit->id,
            'allocated_amount' => 0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    /** Crédito de outro documento (Credit Note): não faz parte do cronograma da PI. */
    private function creditNoteStage(int $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->credit()->create([
            'payable_type' => CreditNote::class,
            'payable_id' => 999,
            'amount' => $amount,
            'currency_code' => 'USD',
            'label' => 'CN-TEST-0001: Discount',
        ]);
    }

    private function actAsPortalUser(): void
    {
        Permission::firstOrCreate(['name' => 'portal:view-financial-summary', 'guard_name' => 'web']);
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo('portal:view-financial-summary');
        $this->actingAs($user);
        Filament::setTenant($this->client);
    }

    private function listStats(): array
    {
        $widget = new ProformaInvoicesListStats;

        return (new \ReflectionMethod($widget, 'getViewData'))->invoke($widget);
    }

    private function summary(): array
    {
        $widget = new PortalProformaInvoiceStats;
        $widget->record = $this->pi->fresh();

        return (new \ReflectionMethod($widget, 'getViewData'))->invoke($widget);
    }

    private function card(array $data, string $labelKey): ?array
    {
        return collect($data['cards'])->firstWhere('label', __($labelKey));
    }

    public function test_applied_discount_does_not_shrink_the_open_balance_of_other_stages(): void
    {
        // Produtos quitados com dinheiro + desconto; a comissão segue em aberto.
        $products = $this->stage(10_000_000);
        $commission = $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($products, 9_800_000);
        $this->applyCredit($products, $discount, 200_000);

        $data = $this->summary();

        $this->assertSame(500_000, $commission->fresh()->remaining_amount);
        $this->assertSame(500_000, $data['totals']['remaining_raw'], 'open balance = the unpaid commission');
        $this->assertSame('USD 50.00', $this->card($data, 'widgets.document_summary.remaining')['value']);

        // Total líquido 1.030 = 980 recebidos em dinheiro + 50 em aberto.
        $this->assertSame('1,030.00', $data['totals']['amount']);
        $this->assertSame('980.00', $data['totals']['paid']);
        $this->assertSame('50.00', $data['totals']['remaining']);
        $this->assertSame('USD 980.00', $this->card($data, 'widgets.document_summary.paid')['value']);
        $this->assertSame(95, $data['progress']);
    }

    public function test_pi_settled_with_cash_plus_discount_is_exactly_fully_paid(): void
    {
        $products = $this->stage(10_000_000);
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($products, 9_800_000);
        $this->applyCredit($products, $discount, 200_000);

        $data = $this->summary();

        $this->assertSame(0, $data['totals']['remaining_raw']);
        $this->assertSame(100, $data['progress'], 'never above 100% because of the discount');
        $this->assertSame('100% '.__('widgets.portal.received'), $this->card($data, 'widgets.document_summary.paid')['description']);
        $this->assertSame(__('widgets.document_summary.fully_paid'), $this->card($data, 'widgets.document_summary.remaining')['description']);
    }

    public function test_discount_not_applied_yet_still_reduces_the_open_balance(): void
    {
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));
        $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($products, 3_000_000);

        $data = $this->summary();

        // 7.000 de produtos + 500 de comissão − 200 de desconto a aplicar.
        $this->assertSame(7_300_000, $data['totals']['remaining_raw']);
        $this->assertSame('300.00', $data['totals']['paid']);
        $this->assertSame(29, $data['progress']);
    }

    public function test_partially_applied_discount_counts_each_part_once(): void
    {
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($products, 3_000_000);
        $this->applyCredit($products, $discount, 50_000);

        $data = $this->summary();

        // Produtos: 10.000 − 3.000 − 50 = 6.950; comissão 500; desconto restante 150.
        $this->assertSame(7_300_000, $data['totals']['remaining_raw']);
        $this->assertSame('300.00', $data['totals']['paid']);
    }

    public function test_waived_stage_and_applied_discount_together(): void
    {
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));
        $this->stage(300_000, ['status' => PaymentScheduleStatus::WAIVED, 'label' => 'Waived fee']);

        $this->allocate($products, 9_800_000);
        $this->applyCredit($products, $discount, 200_000);

        $data = $this->summary();

        $this->assertSame(500_000, $data['totals']['remaining_raw']);
        $this->assertSame('USD 30.00', $this->card($data, 'widgets.document_summary.waived')['value']);
        // Líquido 10.600: 9.800 pagos + 300 isentos = 95%.
        $this->assertSame(95, $data['progress']);
    }

    public function test_list_stats_do_not_count_applied_discount_as_money_received(): void
    {
        $this->actAsPortalUser();

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000,
        ]);
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($products, 9_800_000);
        $this->applyCredit($products, $discount, 200_000);

        $data = $this->listStats();

        // grand_total já é líquido do desconto: 1.000 + 50 − 20.
        $this->assertSame('1,030.00', $data['totalValue']);
        $this->assertSame('980.00', $data['totalPaid']);
        $this->assertSame('50.00', $data['totalRemaining']);
        $this->assertSame(95.1, $data['paymentProgress']);
    }

    public function test_sub_cent_rounding_residue_on_paid_stages_is_not_an_open_balance(): void
    {
        // PI-2026-00006: parcelas pagas com sobra/falta de fração de centavo
        // (a parcela é tratada como quitada). A poeira de uma compensa a da
        // outra; uma PI quitada não pode aparecer com saldo em aberto.
        $under = $this->stage(1_000_020, ['status' => PaymentScheduleStatus::PAID]);
        $over = $this->stage(999_980, ['status' => PaymentScheduleStatus::PAID]);

        $this->allocate($under, 1_000_000);
        $this->allocate($over, 1_000_000);

        $data = $this->summary();

        $this->assertSame(0, $data['totals']['remaining_raw']);
        $this->assertSame(100, $data['progress']);
        $this->assertSame(__('widgets.document_summary.fully_paid'), $this->card($data, 'widgets.document_summary.remaining')['description']);
    }

    public function test_credit_note_applied_to_a_stage_counts_as_payment_of_the_pi(): void
    {
        // PI-2026-00052: o crédito vem de uma Credit Note, fora do cronograma
        // da PI. Nada o abate do total, então na PI ele vale como pagamento.
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));

        $this->allocate($products, 9_400_000);
        $this->applyCredit($products, $this->creditNoteStage(600_000), 600_000);

        $data = $this->summary();

        $this->assertSame(500_000, $data['totals']['remaining_raw']);
        $this->assertSame('1,050.00', $data['totals']['amount']);
        $this->assertSame('1,000.00', $data['totals']['paid']);
        $this->assertSame('USD 1,000.00', $this->card($data, 'widgets.document_summary.paid')['value']);
        $this->assertSame(95, $data['progress']);
    }

    public function test_list_stats_count_credit_note_applied_to_a_stage_as_paid(): void
    {
        $this->actAsPortalUser();

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000,
        ]);
        $products = $this->stage(10_000_000);
        $this->costStage($this->cost(500_000));

        $this->allocate($products, 9_400_000);
        $this->applyCredit($products, $this->creditNoteStage(600_000), 600_000);

        $data = $this->listStats();

        $this->assertSame('1,050.00', $data['totalValue']);
        $this->assertSame('1,000.00', $data['totalPaid']);
        $this->assertSame('50.00', $data['totalRemaining']);
    }
}
