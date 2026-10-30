<?php

namespace Tests\Feature\Inquiries;

use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class RequestSupplierQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    private Inquiry $inquiry;

    /** @var list<InquiryItem> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value, 'currency_code' => 'USD']);
        $this->items = [];
        foreach ([1, 2, 3] as $i) {
            $this->items[] = InquiryItem::factory()->create([
                'inquiry_id' => $this->inquiry->id,
                'sort_order' => $i,
                'target_price' => Money::toMinor(1.5),
            ]);
        }
    }

    private function supplier(): Company
    {
        $company = Company::factory()->supplier()->create();
        $company->companyRoles()->create(['role' => 'supplier']);

        return $company;
    }

    private function request(array $companyIds, array $itemIds): void
    {
        // The checklist defaults to every item; set() replaces it, whereas
        // callAction(data:) would merge the arrays index by index.
        Livewire::test(ViewInquiry::class, ['record' => $this->inquiry->getKey()])
            ->mountAction('requestSupplierQuotation')
            ->set('mountedActions.0.data.company_ids', $companyIds)
            ->set('mountedActions.0.data.inquiry_item_ids', array_map('strval', $itemIds))
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }

    public function test_only_selected_items_go_to_the_supplier_quotation(): void
    {
        $supplier = $this->supplier();

        $this->request([$supplier->id], [$this->items[0]->id, $this->items[2]->id]);

        $sq = SupplierQuotation::where('inquiry_id', $this->inquiry->id)->sole();
        $this->assertEqualsCanonicalizing(
            [$this->items[0]->id, $this->items[2]->id],
            $sq->items->pluck('inquiry_item_id')->all(),
        );
    }

    public function test_each_supplier_can_receive_its_own_items(): void
    {
        $chains = $this->supplier();
        $bearings = $this->supplier();

        $this->request([$chains->id], [$this->items[0]->id]);
        $this->request([$bearings->id], [$this->items[1]->id, $this->items[2]->id]);

        $byCompany = SupplierQuotation::where('inquiry_id', $this->inquiry->id)->get()->keyBy('company_id');
        $this->assertSame([$this->items[0]->id], $byCompany[$chains->id]->items->pluck('inquiry_item_id')->all());
        $this->assertEqualsCanonicalizing(
            [$this->items[1]->id, $this->items[2]->id],
            $byCompany[$bearings->id]->items->pluck('inquiry_item_id')->all(),
        );
    }

    public function test_requesting_again_adds_selected_items_and_keeps_the_existing_ones(): void
    {
        $supplier = $this->supplier();

        $this->request([$supplier->id], [$this->items[0]->id]);
        $this->request([$supplier->id], [$this->items[1]->id]);

        $sq = SupplierQuotation::where('inquiry_id', $this->inquiry->id)->sole();
        $this->assertSame(SupplierQuotationStatus::REQUESTED, $sq->status);
        $this->assertEqualsCanonicalizing(
            [$this->items[0]->id, $this->items[1]->id],
            $sq->items()->pluck('inquiry_item_id')->all(),
        );
    }

    public function test_supplier_cost_comes_from_the_supplier_price_never_from_the_client_target(): void
    {
        $supplier = $this->supplier();
        $this->items[1]->product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'unit_price' => Money::toMinor(0.8),
        ]);

        $this->request([$supplier->id], [$this->items[0]->id, $this->items[1]->id]);

        $costs = SupplierQuotation::where('inquiry_id', $this->inquiry->id)->sole()
            ->items->pluck('unit_cost', 'inquiry_item_id');
        $this->assertSame(0, (int) $costs[$this->items[0]->id]);
        $this->assertSame(Money::toMinor(0.8), (int) $costs[$this->items[1]->id]);
    }
}
