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
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\ProformaInvoicePaymentSplit;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Pages\ListProformaInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lista de PIs do Portal do Cliente: o pago aparece separado em produtos e
 * outros custos (comissão, frete, desconto…), ao lado do total de cada grupo.
 */
class PortalPiListPaymentSplitTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');

        foreach (['portal:view-proforma-invoices', 'portal:view-financial-summary'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
    }

    private function productStage(int $amount, array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'amount' => $amount,
            'currency_code' => 'USD',
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
    private function costStage(AdditionalCost $cost, array $overrides = []): PaymentScheduleItem
    {
        return $this->productStage(abs($cost->amount), array_merge([
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Cost: '.$cost->description,
            'is_credit' => $cost->amount < 0,
        ], $overrides));
    }

    private function payment(int $amount, PaymentStatus $status = PaymentStatus::APPROVED): Payment
    {
        return Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    private function allocate(PaymentScheduleItem $psi, int $amount, PaymentStatus $status = PaymentStatus::APPROVED): void
    {
        PaymentAllocation::create([
            'payment_id' => $this->payment($amount, $status)->id,
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

    /** @return array{products: int, products_waived: int, other: int, other_waived: int} */
    private function split(): array
    {
        $row = ProformaInvoicePaymentSplit::addSelects(ProformaInvoice::query())->findOrFail($this->pi->id);

        return [
            'products' => (int) $row->getAttribute(ProformaInvoicePaymentSplit::PRODUCTS_PAID),
            'products_waived' => (int) $row->getAttribute(ProformaInvoicePaymentSplit::PRODUCTS_WAIVED),
            'other' => (int) $row->getAttribute(ProformaInvoicePaymentSplit::OTHER_COSTS_PAID),
            'other_waived' => (int) $row->getAttribute(ProformaInvoicePaymentSplit::OTHER_COSTS_WAIVED),
        ];
    }

    public function test_paid_is_split_between_product_stages_and_additional_cost_stages(): void
    {
        $deposit = $this->productStage(3_000_000);
        $balance = $this->productStage(7_000_000);
        $commission = $this->costStage($this->cost(500_000));

        $this->allocate($deposit, 3_000_000);
        $this->allocate($balance, 1_000_000);
        $this->allocate($commission, 200_000);

        $this->assertSame(
            ['products' => 4_000_000, 'products_waived' => 0, 'other' => 200_000, 'other_waived' => 0],
            $this->split(),
        );
    }

    public function test_only_approved_payments_on_the_client_side_of_this_pi_count(): void
    {
        $stage = $this->productStage(10_000_000);
        $commission = $this->costStage($this->cost(500_000));

        $this->allocate($stage, 2_000_000);
        $this->allocate($stage, 1_000_000, PaymentStatus::PENDING_APPROVAL);
        $this->allocate($commission, 100_000, PaymentStatus::CANCELLED);

        // Pagamento excluído (soft delete) deixa de contar.
        $this->allocate($stage, 500_000);
        Payment::query()->latest('id')->first()->delete();

        // Perna a pagar ao forwarder do mesmo custo: não é dívida do cliente.
        $forwarderLeg = $this->costStage($this->cost(900_000, AdditionalCostType::FREIGHT), [
            'notes' => PaymentScheduleItem::FORWARDER_PAYABLE_TAG,
        ]);
        $this->allocate($forwarderLeg, 900_000);

        // Outra PI do mesmo cliente não vaza para esta.
        $other = ProformaInvoice::factory()->create(['company_id' => $this->client->id]);
        $this->allocate(PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $other->id,
            'amount' => 4_000_000,
        ]), 4_000_000);

        $this->assertSame(
            ['products' => 2_000_000, 'products_waived' => 0, 'other' => 0, 'other_waived' => 0],
            $this->split(),
        );
    }

    public function test_discount_used_on_a_product_stage_counts_as_paid_there_and_never_as_negative_paid(): void
    {
        // PI-2026-00070: produtos quitados com dinheiro + desconto, comissão
        // ainda aberta. "Pago de outros" não pode sair negativo.
        $stage = $this->productStage(10_000_000);
        $commission = $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));

        $this->allocate($stage, 9_800_000);
        $this->applyCredit($stage, $discount, 200_000);

        $this->assertSame(
            ['products' => 10_000_000, 'products_waived' => 0, 'other' => 0, 'other_waived' => 0],
            $this->split(),
        );

        $this->allocate($commission, 500_000);

        $this->assertSame(500_000, $this->split()['other']);
    }

    public function test_waived_balance_is_reported_per_group_net_of_what_was_already_paid(): void
    {
        $stage = $this->productStage(10_000_000, ['status' => PaymentScheduleStatus::WAIVED]);
        $this->allocate($stage, 4_000_000);
        $this->costStage($this->cost(300_000), ['status' => PaymentScheduleStatus::WAIVED]);

        $this->assertSame(
            ['products' => 4_000_000, 'products_waived' => 6_000_000, 'other' => 0, 'other_waived' => 300_000],
            $this->split(),
        );
    }

    public function test_portal_list_shows_totals_and_paid_of_each_group(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo(['portal:view-proforma-invoices', 'portal:view-financial-summary']);
        $this->actingAs($user);
        Filament::setTenant($this->client);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000,
        ]);
        $stage = $this->productStage(10_000_000);
        $commission = $this->costStage($this->cost(500_000));
        $this->allocate($stage, 2_500_000);
        $this->allocate($commission, 500_000);

        // PI sem custos nem pagamentos: outros custos ficam em branco.
        $bare = ProformaInvoice::factory()->create(['company_id' => $this->client->id, 'currency_code' => 'USD']);

        Livewire::test(ListProformaInvoices::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->pi, $bare])
            ->assertTableColumnFormattedStateSet('total', 'USD 1,000.00', $this->pi)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::PRODUCTS_PAID, 'USD 250.00', $this->pi)
            ->assertTableColumnFormattedStateSet('other_costs_total', 'USD 50.00', $this->pi)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::OTHER_COSTS_PAID, 'USD 50.00', $this->pi)
            ->assertTableColumnFormattedStateSet('grand_total', 'USD 1,050.00', $this->pi)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::PRODUCTS_PAID, 'USD 0.00', $bare)
            ->assertTableColumnFormattedStateSet('other_costs_total', '—', $bare)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::OTHER_COSTS_PAID, '—', $bare)
            ->sortTable(ProformaInvoicePaymentSplit::PRODUCTS_PAID, 'desc')
            ->assertCanSeeTableRecords([$this->pi, $bare], inOrder: true);
    }

    public function test_discount_stays_out_of_other_costs_and_is_shown_as_a_note(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo(['portal:view-proforma-invoices', 'portal:view-financial-summary']);
        $this->actingAs($user);
        Filament::setTenant($this->client);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000,
        ]);
        $stage = $this->productStage(10_000_000);
        $this->costStage($this->cost(500_000));
        $discount = $this->costStage($this->cost(-200_000, AdditionalCostType::DISCOUNT));
        $this->allocate($stage, 9_800_000);
        $this->applyCredit($stage, $discount, 200_000);

        Livewire::test(ListProformaInvoices::class)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::PRODUCTS_PAID, 'USD 1,000.00', $this->pi)
            ->assertTableColumnFormattedStateSet('other_costs_total', 'USD 50.00', $this->pi)
            ->assertTableColumnHasDescription('other_costs_total', __('forms.labels.discount_granted').': USD 20.00', $this->pi)
            ->assertTableColumnFormattedStateSet(ProformaInvoicePaymentSplit::OTHER_COSTS_PAID, 'USD 0.00', $this->pi)
            ->assertTableColumnFormattedStateSet('grand_total', 'USD 1,030.00', $this->pi);
    }

    public function test_waived_balance_is_shown_under_the_paid_column(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo(['portal:view-proforma-invoices', 'portal:view-financial-summary']);
        $this->actingAs($user);
        Filament::setTenant($this->client);

        $this->productStage(10_000_000, ['status' => PaymentScheduleStatus::WAIVED]);

        Livewire::test(ListProformaInvoices::class)
            ->assertTableColumnHasDescription(
                ProformaInvoicePaymentSplit::PRODUCTS_PAID,
                __('widgets.document_summary.waived').': USD 1,000.00',
                $this->pi,
            );
    }

    public function test_financial_columns_are_hidden_without_the_financial_summary_permission(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo('portal:view-proforma-invoices');
        $this->actingAs($user);
        Filament::setTenant($this->client);

        Livewire::test(ListProformaInvoices::class)
            ->assertOk()
            ->assertTableColumnHidden(ProformaInvoicePaymentSplit::PRODUCTS_PAID)
            ->assertTableColumnHidden('other_costs_total')
            ->assertTableColumnHidden(ProformaInvoicePaymentSplit::OTHER_COSTS_PAID);
    }
}
