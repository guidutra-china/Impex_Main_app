<?php

namespace Tests\Feature\Forms;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\SupplierQuotations\Pages\EditSupplierQuotation;
use App\Filament\Support\RecentRecordSelect;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PI-2026-00011 ↔ INQ-2026-00020: o campo listava só as 100 inquiries mais
 * recentes. Quando a inquiry da PI saiu dessa janela, o form mostrava o id
 * cru ("20") e não salvava — a validação do Select exige rótulo para o valor.
 */
class OldLinkedRecordStaysEditableTest extends TestCase
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

    private function oldInquiry(): Inquiry
    {
        $old = Inquiry::factory()->create(['reference' => 'INQ-2026-00020']);
        Inquiry::factory()->count(RecentRecordSelect::PRELOADED + 1)->create();

        return $old;
    }

    public function test_pi_linked_to_an_inquiry_outside_the_preloaded_window_shows_its_label_and_saves(): void
    {
        $old = $this->oldInquiry();
        $pi = ProformaInvoice::factory()->create(['inquiry_id' => $old->id, 'company_id' => $old->company_id]);

        Livewire::test(EditProformaInvoice::class, ['record' => $pi->getRouteKey()])
            ->assertFormSet(['inquiry_id' => $old->id])
            ->assertFormFieldExists('inquiry_id', function (Select $select) use ($old): bool {
                // Fora das pré-carregadas, mas com rótulo — não o id cru.
                return ! array_key_exists($old->id, $select->getOptions())
                    && str_starts_with((string) $select->getOptionLabel(), 'INQ-2026-00020 — ');
            })
            ->call('save')
            ->assertHasNoFormErrors(['inquiry_id']);
    }

    public function test_an_old_inquiry_can_still_be_found_by_typing(): void
    {
        $old = $this->oldInquiry();
        $pi = ProformaInvoice::factory()->create(['inquiry_id' => $old->id, 'company_id' => $old->company_id]);

        Livewire::test(EditProformaInvoice::class, ['record' => $pi->getRouteKey()])
            ->assertFormFieldExists('inquiry_id', function (Select $select) use ($old): bool {
                return array_key_exists($old->id, $select->getSearchResults('INQ-2026-00020'))
                    && array_key_exists($old->id, $select->getSearchResults($old->company->name));
            });
    }

    public function test_supplier_quotation_linked_to_an_old_inquiry_saves(): void
    {
        $old = $this->oldInquiry();
        $sq = SupplierQuotation::factory()->create(['inquiry_id' => $old->id]);

        Livewire::test(EditSupplierQuotation::class, ['record' => $sq->getRouteKey()])
            ->assertFormSet(['inquiry_id' => $old->id])
            ->call('save')
            ->assertHasNoFormErrors(['inquiry_id']);
    }

    public function test_purchase_order_linked_to_an_old_pi_saves(): void
    {
        $oldPi = ProformaInvoice::factory()->create(['reference' => 'PI-2026-00001']);
        ProformaInvoice::factory()->count(RecentRecordSelect::PRELOADED + 1)->create();
        $po = PurchaseOrder::factory()->create(['proforma_invoice_id' => $oldPi->id]);

        Livewire::test(EditPurchaseOrder::class, ['record' => $po->getRouteKey()])
            ->assertFormSet(['proforma_invoice_id' => $oldPi->id])
            ->assertFormFieldExists('proforma_invoice_id', fn (Select $select): bool => str_starts_with((string) $select->getOptionLabel(), 'PI-2026-00001 — '))
            ->call('save')
            ->assertHasNoFormErrors(['proforma_invoice_id']);
    }
}
