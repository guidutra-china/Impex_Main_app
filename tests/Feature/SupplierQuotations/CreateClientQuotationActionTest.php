<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CreateClientQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    private function buildSq(
        bool $withPricedItem = true,
        SupplierQuotationStatus $status = SupplierQuotationStatus::RECEIVED,
    ): SupplierQuotation {
        $client = Company::factory()->create();
        // O select de cliente do modal filtra por papel (Company::withRole).
        CompanyRoleAssignment::create([
            'company_id' => $client->id,
            'role' => CompanyRole::CLIENT,
        ]);
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create();
        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $sq = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => $status,
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_cost' => $withPricedItem ? 100000 : 0,
        ]);

        return $sq;
    }

    public function test_modal_prefills_client_and_creates_quotation(): void
    {
        $sq = $this->buildSq();
        $inquiry = $sq->inquiry;

        // mountAction() + callAction() no MESMO teste do componente colide: com a action
        // já montada, callAction() tenta resolvê-la como filha de si mesma
        // (ActionNotResolvableException). Verificamos o prefill num componente Livewire
        // fresco e disparamos a criação em outro — callAction() já mount+call+unmount
        // internamente, sem precisar de mountAction() antes.
        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertActionDataSet([
                'company_id' => $inquiry->company_id,
                'currency_code' => 'USD',
                // O Select de commission_type é enum-backed: fillForm() grava a string,
                // mas o componente hidrata de volta para o caso do enum ao ler o estado
                // (mesmo comportamento observado em Select::options(EnumClass::class)).
                'commission_type' => CommissionType::EMBEDDED,
                'commission_rate' => 10,
                'validity_days' => 30,
            ]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $inquiry->company_id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
            ]);

        $quotation = Quotation::where('inquiry_id', $inquiry->id)->first();
        $this->assertNotNull($quotation);
        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame(110000, $quotation->items()->first()->unit_price);
        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sq->fresh()->status);
    }

    public function test_action_is_hidden_when_no_item_is_priced(): void
    {
        $sq = $this->buildSq(withPricedItem: false);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->assertActionHidden('createClientQuotation');
    }

    /**
     * O allow-list de status aceitos como fonte é o mesmo consultado pela action de
     * domínio (CreateQuotationFromSupplierQuotationAction::canBeSource()) — este teste
     * pinça os dois extremos: SELECTED/REJECTED continuam entrando na lista, REQUESTED
     * continua fora dela. Excluir SELECTED ou REJECTED da lista real deixaria este
     * teste vermelho, ao contrário do que acontecia quando a lista vivia duplicada
     * só na trait.
     */
    public function test_action_visible_for_selected_and_rejected_hidden_for_requested(): void
    {
        $selected = $this->buildSq(status: SupplierQuotationStatus::SELECTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $selected->getKey()])
            ->assertActionVisible('createClientQuotation');

        $rejected = $this->buildSq(status: SupplierQuotationStatus::REJECTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $rejected->getKey()])
            ->assertActionVisible('createClientQuotation');

        $requested = $this->buildSq(status: SupplierQuotationStatus::REQUESTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $requested->getKey()])
            ->assertActionHidden('createClientQuotation');
    }
}
