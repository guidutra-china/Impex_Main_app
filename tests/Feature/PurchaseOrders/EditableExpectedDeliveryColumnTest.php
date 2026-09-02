<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A data de entrega prevista muda com frequência; editá-la exigia abrir a PO.
 * Agora é editável na própria lista — e a permissão é verificada no servidor,
 * não só escondendo o campo.
 */
class EditableExpectedDeliveryColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function actAsEditor(): void
    {
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    private function editCell(PurchaseOrder $po, mixed $value): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ListPurchaseOrders::class)
            ->call('updateTableColumnState', 'expected_delivery_date', (string) $po->getKey(), $value);
    }

    /**
     * O wrapper da coluna faz preventDefault no clique (para não abrir a
     * linha), e isso cancela a abertura do calendário nativo do input date:
     * o usuário via só o ícone. O input precisa abrir o picker por conta
     * própria (Filament só faz isso para type=color — issue #15895).
     */
    public function test_the_date_input_opens_the_native_picker_itself(): void
    {
        $this->actAsEditor();
        PurchaseOrder::factory()->create(['expected_delivery_date' => '2026-09-01']);

        Livewire::test(ListPurchaseOrders::class)
            ->toggleAllTableColumns()
            ->assertSeeHtml('type="date"')
            ->assertSeeHtml('this.showPicker()');
    }

    public function test_editing_the_cell_persists_the_new_date(): void
    {
        $this->actAsEditor();
        $po = PurchaseOrder::factory()->create(['expected_delivery_date' => '2026-09-01']);

        $this->editCell($po, '2026-10-15');

        $this->assertSame('2026-10-15', $po->fresh()->expected_delivery_date->toDateString());
    }

    public function test_clearing_the_cell_stores_null(): void
    {
        $this->actAsEditor();
        $po = PurchaseOrder::factory()->create(['expected_delivery_date' => '2026-09-01']);

        $this->editCell($po, '');

        $this->assertNull($po->fresh()->expected_delivery_date);
    }

    public function test_an_invalid_date_is_rejected_and_the_stored_value_is_kept(): void
    {
        $this->actAsEditor();
        $po = PurchaseOrder::factory()->create(['expected_delivery_date' => '2026-09-01']);

        $this->editCell($po, 'não-é-data');

        $this->assertSame(
            '2026-09-01',
            $po->fresh()->expected_delivery_date->toDateString(),
            'valor inválido não pode sobrescrever a data gravada',
        );
    }

    public function test_a_user_without_edit_permission_cannot_change_the_date(): void
    {
        $viewer = User::factory()->create();
        // Pode tudo, menos editar POs — inclusive ver a lista.
        Gate::before(fn (User $u, string $ability) => $u->id === $viewer->id
            ? ($ability === 'edit-purchase-orders' ? null : true)
            : null);
        $this->actingAs($viewer);

        $po = PurchaseOrder::factory()->create(['expected_delivery_date' => '2026-09-01']);

        // Requisição forjada direto no método do Livewire: o guard tem de
        // valer no servidor, não só desabilitando o input.
        $this->editCell($po, '2026-12-25');

        $this->assertSame(
            '2026-09-01',
            $po->fresh()->expected_delivery_date->toDateString(),
            'sem permissão de edição a data não pode mudar',
        );
    }
}
