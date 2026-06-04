<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Exceptions\TransitionBlockedException;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_throws_when_proforma_invoice_finalization_is_blocked(): void
    {
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

        $this->assertNotEmpty($pi->getFinalizationBlockers());

        try {
            app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            ProformaInvoiceStatus::CONFIRMED->value,
            $pi->fresh()->status->value,
            'PI status must be unchanged when blocked.'
        );
    }

    public function test_execute_succeeds_when_proforma_invoice_has_no_blockers(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        $this->assertEmpty($pi->getFinalizationBlockers());

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);

        $this->assertSame(
            ProformaInvoiceStatus::FINALIZED->value,
            $pi->fresh()->status->value,
        );
    }
}
