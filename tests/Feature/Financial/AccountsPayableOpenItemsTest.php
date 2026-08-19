<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Pages\AccountsPayableOpenItems;
use App\Filament\Pages\Widgets\AccountsPayableTotalsWidget;
use App\Filament\Resources\Finance\AccountsPayable\Pages\CreateAccountsPayable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paridade da worklist de Contas a Pagar com a de Contas a Receber: a página
 * renderiza, lista só o lado fornecedor e o "Registrar pagamento" leva os
 * itens escolhidos para o formulário de pagamento.
 */
class AccountsPayableOpenItemsTest extends TestCase
{
    use RefreshDatabase;

    private function adminActing(): User
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        return $admin;
    }

    private function supplierPo(Company $supplier): PurchaseOrder
    {
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'USD']);

        return PurchaseOrder::create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
    }

    private function openPoItem(PurchaseOrder $po, int $amount = 1_000_000): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);
    }

    public function test_the_page_renders_and_lists_supplier_items(): void
    {
        $this->adminActing();

        $supplier = Company::factory()->create();
        $item = $this->openPoItem($this->supplierPo($supplier));

        Livewire::test(AccountsPayableOpenItems::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$item]);
    }

    public function test_client_receivables_stay_out_of_the_payables_list(): void
    {
        $this->adminActing();

        $pi = ProformaInvoice::factory()->create(['currency_code' => 'USD']);
        $clientItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayableOpenItems::class)
            ->assertCanNotSeeTableRecords([$clientItem]);
    }

    /**
     * Regressão: o closure de busca recebia um parâmetro com nome diferente de
     * $query, o Filament não conseguia injetar e o container montava um Builder
     * sem model — "newQueryWithoutRelationships() on null" ao pesquisar.
     */
    public function test_searching_by_supplier_name_filters_the_list(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Industrial']);
        $globex = Company::factory()->create(['name' => 'Globex Manufacturing']);
        $mine = $this->openPoItem($this->supplierPo($acme));
        $other = $this->openPoItem($this->supplierPo($globex));

        Livewire::test(AccountsPayableOpenItems::class)
            ->searchTable('Acme')
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filtering_by_supplier_narrows_the_list(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Industrial']);
        $globex = Company::factory()->create(['name' => 'Globex Manufacturing']);
        $mine = $this->openPoItem($this->supplierPo($acme));
        $other = $this->openPoItem($this->supplierPo($globex));

        Livewire::test(AccountsPayableOpenItems::class)
            ->filterTable('counterparty', $acme->id)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * A perna pagável de um custo adicional pertence ao fornecedor nomeado no
     * custo, não ao dono do documento — o filtro tem que concordar com o nome
     * exibido na coluna.
     */
    public function test_filtering_by_supplier_catches_supplier_payable_cost_rows(): void
    {
        $this->adminActing();

        $supplier = Company::factory()->create(['name' => 'Acme Industrial']);
        $pi = ProformaInvoice::factory()->create(['currency_code' => 'USD']);

        $cost = $pi->additionalCosts()->create([
            'cost_type' => 'testing',
            'description' => 'Lab test billed by the factory',
            'amount' => 500_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 500_000,
            'billable_to' => BillableTo::CLIENT->value,
            'supplier_company_id' => $supplier->id,
            'supplier_payable_amount' => 500_000,
            'status' => 'pending',
        ]);

        $costItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Testing payable',
            'percentage' => 0,
            'amount' => 500_000,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'notes' => PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.' lab',
        ]);

        Livewire::test(AccountsPayableOpenItems::class)
            ->filterTable('counterparty', $supplier->id)
            ->assertCanSeeTableRecords([$costItem]);
    }

    public function test_the_totals_widget_follows_the_table_filters(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Industrial']);
        $globex = Company::factory()->create(['name' => 'Globex Manufacturing']);
        $this->openPoItem($this->supplierPo($acme), 3_000_000);
        $this->openPoItem($this->supplierPo($globex), 2_000_000);

        // Sem filtro: soma tudo.
        Livewire::test(AccountsPayableTotalsWidget::class)
            ->assertSee('USD 500.00');

        // Filtrado por fornecedor: só o que está na tela.
        Livewire::test(AccountsPayableTotalsWidget::class, [
            'tableFilters' => ['counterparty' => ['value' => $acme->id]],
        ])
            ->assertSee('USD 300.00')
            ->assertDontSee('USD 500.00');
    }

    public function test_the_totals_widget_follows_the_search(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Industrial']);
        $globex = Company::factory()->create(['name' => 'Globex Manufacturing']);
        $this->openPoItem($this->supplierPo($acme), 3_000_000);
        $this->openPoItem($this->supplierPo($globex), 2_000_000);

        Livewire::test(AccountsPayableTotalsWidget::class, ['tableSearch' => 'Globex'])
            ->assertSee('USD 200.00')
            ->assertDontSee('USD 500.00');
    }

    public function test_bulk_action_same_supplier_redirects_to_create(): void
    {
        $this->adminActing();

        $supplier = Company::factory()->create();
        $po = $this->supplierPo($supplier);
        $a = $this->openPoItem($po);
        $b = $this->openPoItem($po);

        Livewire::test(AccountsPayableOpenItems::class)
            ->callTableBulkAction('registrarPagamento', [$a, $b])
            ->assertRedirect(CreateAccountsPayable::getUrl([
                'company_id' => $supplier->id,
                'schedule_item_ids' => "{$a->id},{$b->id}",
            ]));
    }

    public function test_the_create_form_is_prefilled_from_the_worklist_link(): void
    {
        $this->adminActing();

        $supplier = Company::factory()->create();
        $supplier->companyRoles()->create(['role' => 'supplier']);
        $po = $this->supplierPo($supplier);
        $a = $this->openPoItem($po);
        $b = $this->openPoItem($po);

        Livewire::withQueryParams([
            'company_id' => $supplier->id,
            'schedule_item_ids' => "{$a->id},{$b->id}",
        ])
            ->test(CreateAccountsPayable::class)
            ->assertOk()
            ->assertFormSet([
                'company_id' => $supplier->id,
                'currency_code' => 'USD',
                'amount' => '200.00',
            ]);
    }

    public function test_bulk_action_mixed_suppliers_is_blocked(): void
    {
        $this->adminActing();

        $a = $this->openPoItem($this->supplierPo(Company::factory()->create()));
        $b = $this->openPoItem($this->supplierPo(Company::factory()->create()));

        Livewire::test(AccountsPayableOpenItems::class)
            ->callTableBulkAction('registrarPagamento', [$a, $b])
            ->assertNotified()
            ->assertNoRedirect();
    }
}
