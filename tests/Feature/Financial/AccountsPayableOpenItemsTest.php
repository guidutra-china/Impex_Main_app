<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Pages\AccountsPayableOpenItems;
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
