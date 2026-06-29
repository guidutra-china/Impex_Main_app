<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\CreateDebitNote;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Criar Nota de Crédito/Débito" buttons inside a PO/PI open the create
 * screen with the context in the URL query (party_type, company_id and the
 * document link). These tests guard that the create pages actually consume
 * those params to prefill the form — the bug was that they were ignored.
 */
class NoteCreatePrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    private function supplier(): Company
    {
        $company = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $company->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        return $company;
    }

    private function client(): Company
    {
        $company = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        return $company;
    }

    public function test_credit_note_prefills_supplier_and_po_from_po_context(): void
    {
        $supplier = $this->supplier();
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id]);

        Livewire::withQueryParams([
            'party_type' => 'supplier',
            'company_id' => (string) $supplier->id,
            'purchase_order_id' => (string) $po->id,
        ])->test(CreateCreditNote::class)->assertFormSet([
            'party_type' => PartyType::SUPPLIER,
            'company_id' => $supplier->id,
            'purchase_order_id' => $po->id,
        ]);
    }

    public function test_debit_note_from_po_prefills_supplier_not_the_default_client(): void
    {
        $supplier = $this->supplier();
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id]);

        Livewire::withQueryParams([
            'party_type' => 'supplier',
            'company_id' => (string) $supplier->id,
            'purchase_order_id' => (string) $po->id,
        ])->test(CreateDebitNote::class)->assertFormSet([
            'party_type' => PartyType::SUPPLIER,
            'company_id' => $supplier->id,
            'purchase_order_id' => $po->id,
        ]);
    }

    public function test_debit_note_prefills_client_and_pi_from_pi_context(): void
    {
        $client = $this->client();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);

        Livewire::withQueryParams([
            'party_type' => 'client',
            'company_id' => (string) $client->id,
            'proforma_invoice_id' => (string) $pi->id,
        ])->test(CreateDebitNote::class)->assertFormSet([
            'party_type' => PartyType::CLIENT,
            'company_id' => $client->id,
            'proforma_invoice_id' => $pi->id,
        ]);
    }

    public function test_defaults_apply_when_opened_without_query(): void
    {
        Livewire::withQueryParams([])->test(CreateCreditNote::class)
            ->assertFormSet(['party_type' => PartyType::SUPPLIER]);

        Livewire::withQueryParams([])->test(CreateDebitNote::class)
            ->assertFormSet(['party_type' => PartyType::CLIENT]);
    }
}
