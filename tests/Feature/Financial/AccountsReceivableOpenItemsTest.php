<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Pages\AccountsReceivableOpenItems;
use App\Filament\Pages\Widgets\AccountsReceivableTotalsWidget;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\CreateAccountsReceivable;
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AccountsReceivableOpenItemsTest extends TestCase
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

    private function prefillHelper(): object
    {
        return new class
        {
            use HasPaymentAllocationPersistence;

            public function build(PaymentDirection $direction): array
            {
                return $this->buildScheduleItemsPrefill($direction);
            }
        };
    }

    private function openPiItem(ProformaInvoice $pi, int $amount = 1_000_000, string $currency = 'USD'): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => $currency,
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);
    }

    public function test_prefill_multi_item_same_currency_sums_amount(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);
        $a = $this->openPiItem($pi, 3_000_000);
        $b = $this->openPiItem($pi, 2_000_000);

        $request = Request::create('/x', 'GET', [
            'company_id' => $client->id,
            'schedule_item_ids' => "{$a->id},{$b->id}",
        ]);
        $this->app->instance('request', $request);

        $data = $this->prefillHelper()->build(PaymentDirection::INBOUND);

        $this->assertSame($client->id, $data['company_id']);
        $this->assertSame('USD', $data['currency_code']);
        $this->assertSame('500.00', $data['amount']);
        $this->assertCount(2, $data['allocations']);
        $this->assertSame('300.00', $data['allocations'][0]['allocated_amount']);
    }

    public function test_prefill_multi_currency_leaves_amount_unset(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);
        $a = $this->openPiItem($pi, 3_000_000, 'USD');
        $b = $this->openPiItem($pi, 2_000_000, 'BRL');

        $request = Request::create('/x', 'GET', [
            'company_id' => $client->id,
            'schedule_item_ids' => "{$a->id},{$b->id}",
        ]);
        $this->app->instance('request', $request);

        $data = $this->prefillHelper()->build(PaymentDirection::INBOUND);

        $this->assertArrayNotHasKey('amount', $data);
        $this->assertArrayNotHasKey('currency_code', $data);
        $this->assertCount(2, $data['allocations']);
        $this->assertNull($data['allocations'][0]['allocated_amount']);
    }

    /**
     * Regressão: o closure de busca recebia um parâmetro com nome diferente de
     * $query, o Filament não conseguia injetar e o container montava um Builder
     * sem model — "newQueryWithoutRelationships() on null" ao pesquisar.
     */
    public function test_searching_by_client_name_filters_the_list(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Trading']);
        $globex = Company::factory()->create(['name' => 'Globex Imports']);
        $mine = $this->openPiItem(ProformaInvoice::factory()->create(['company_id' => $acme->id]));
        $other = $this->openPiItem(ProformaInvoice::factory()->create(['company_id' => $globex->id]));

        Livewire::test(AccountsReceivableOpenItems::class)
            ->searchTable('Acme')
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filtering_by_client_narrows_the_list_and_the_totals(): void
    {
        $this->adminActing();

        $acme = Company::factory()->create(['name' => 'Acme Trading']);
        $globex = Company::factory()->create(['name' => 'Globex Imports']);
        $mine = $this->openPiItem(ProformaInvoice::factory()->create(['company_id' => $acme->id]), 3_000_000);
        $other = $this->openPiItem(ProformaInvoice::factory()->create(['company_id' => $globex->id]), 2_000_000);

        Livewire::test(AccountsReceivableOpenItems::class)
            ->filterTable('counterparty', $acme->id)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(AccountsReceivableTotalsWidget::class)
            ->assertSee('USD 500.00');

        Livewire::test(AccountsReceivableTotalsWidget::class, [
            'tableFilters' => ['counterparty' => ['value' => $acme->id]],
        ])
            ->assertSee('USD 300.00')
            ->assertDontSee('USD 500.00');
    }

    public function test_bulk_action_same_company_redirects_to_create(): void
    {
        $this->adminActing();

        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);
        $a = $this->openPiItem($pi);
        $b = $this->openPiItem($pi);

        Livewire::test(AccountsReceivableOpenItems::class)
            ->callTableBulkAction('registrarPagamento', [$a, $b])
            ->assertRedirect(CreateAccountsReceivable::getUrl([
                'company_id' => $client->id,
                'schedule_item_ids' => "{$a->id},{$b->id}",
            ]));
    }

    public function test_bulk_action_mixed_companies_is_blocked(): void
    {
        $this->adminActing();

        $clientA = Company::factory()->create();
        $clientB = Company::factory()->create();
        $piA = ProformaInvoice::factory()->create(['company_id' => $clientA->id]);
        $piB = ProformaInvoice::factory()->create(['company_id' => $clientB->id]);
        $a = $this->openPiItem($piA);
        $b = $this->openPiItem($piB);

        Livewire::test(AccountsReceivableOpenItems::class)
            ->callTableBulkAction('registrarPagamento', [$a, $b])
            ->assertNotified()
            ->assertNoRedirect();
    }
}
