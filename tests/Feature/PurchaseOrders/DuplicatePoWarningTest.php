<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicatePoWarningTest extends TestCase
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

    public function test_create_form_warns_when_active_po_exists_for_same_pi_and_supplier(): void
    {
        $supplier = Company::factory()->create();
        $supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER]);
        $pi = ProformaInvoice::factory()->create();

        $existing = PurchaseOrder::factory()->create([
            'reference' => 'PO-2026-99999',
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
        ]);

        $component = Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'proforma_invoice_id' => $pi->id,
                'supplier_company_id' => $supplier->id,
            ]);

        $component->assertSee($existing->reference);

        // Soft-deletada não deve disparar o aviso.
        $existing->delete();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'proforma_invoice_id' => $pi->id,
                'supplier_company_id' => $supplier->id,
            ])
            ->assertDontSee($existing->reference);
    }

    public function test_two_active_pos_for_same_pi_and_supplier_can_coexist(): void
    {
        $supplier = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create();

        PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
        ]);
        PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
        ]);

        $this->assertSame(2, PurchaseOrder::query()
            ->where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->count());
    }
}
