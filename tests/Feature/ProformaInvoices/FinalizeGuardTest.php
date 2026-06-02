<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FinalizeGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The table row "Finalizar" action goes through the generic StatusTransitionActions
     * component, which historically did NOT run ProformaInvoice::getFinalizationBlockers().
     * That let a user finalize a PI from the list table even with unresolved payments /
     * unshipped items — the exact bypass the header finalizeAction already prevents.
     */
    public function test_finalizing_via_table_action_is_blocked_when_items_not_fully_shipped(): void
    {
        $admin = User::factory()->create();
        // Bypass Filament/resource policies; this test is about the business blocker, not RBAC.
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);
        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => 100,
            'sort_order' => 0,
        ]);

        // Sanity: there is a genuine finalization blocker (item not fully shipped).
        $this->assertNotEmpty($pi->getFinalizationBlockers());

        Livewire::test(ListProformaInvoices::class)
            ->callTableAction('transition_to_finalized', $pi);

        $this->assertSame(
            ProformaInvoiceStatus::CONFIRMED->value,
            $pi->fresh()->status->value,
            'PI must NOT finalize via the table action while finalization is blocked.'
        );
    }

    public function test_finalizing_via_table_action_succeeds_when_there_are_no_blockers(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        // No items, no payment schedule items, no additional costs => nothing blocks finalization.
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        $this->assertEmpty($pi->getFinalizationBlockers());

        Livewire::test(ListProformaInvoices::class)
            ->callTableAction('transition_to_finalized', $pi);

        $this->assertSame(
            ProformaInvoiceStatus::FINALIZED->value,
            $pi->fresh()->status->value,
            'PI must finalize via the table action when nothing blocks it.'
        );
    }
}
