<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\PaymentScheduleRelationManager;
use App\Models\User;
use Database\Factories\PaymentScheduleItemFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The Shipment payment schedule tab must surface the client-side (PI) parcels
 * that this shipment is carrying — including document-level stages such as
 * "100% in advance" (order_date), which never get a shipment-specific carve-out.
 * Without them a shipment of a 100%-in-advance PI shows only the additional
 * costs and looks like the client owes nothing (prod: SH-2026-00041 / PI-2026-00078).
 */
class ShipmentPaymentScheduleShowsPiInstallmentsTest extends TestCase
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

    private function shipmentCarrying(ProformaInvoice $pi, int $quantity = 10, int $unitPrice = 1_000): Shipment
    {
        $shipment = Shipment::factory()->create([
            'company_id' => $pi->company_id,
            'currency_code' => 'USD',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Item',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $quantity,
            'sort_order' => 1,
        ]);

        return $shipment;
    }

    private function table(Shipment $shipment)
    {
        return Livewire::test(PaymentScheduleRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ])->assertSuccessful();
    }

    public function test_document_level_pi_installment_of_a_carried_pi_is_listed(): void
    {
        $pi = ProformaInvoice::factory()->create(['reference' => 'PI-2026-00078']);
        $shipment = $this->shipmentCarrying($pi);

        $installment = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 432_506_41,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $this->table($shipment)
            ->assertCanSeeTableRecords([$installment])
            ->assertSee('PI-2026-00078');
    }

    public function test_ship_specific_pi_installment_is_listed(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        $installment = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'label' => '70% — Shipment Date [SH-1 / '.$pi->reference.']',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $this->table($shipment)->assertCanSeeTableRecords([$installment]);
    }

    public function test_remaining_rows_and_foreign_pis_are_not_listed(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        // [remaining]: shipment-dependent condition with no shipment link — it is
        // the not-yet-shipped balance of the PI, so it does not belong here.
        $remaining = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '70% — Shipment Date [remaining]',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        // A document-level parcel of a PI this shipment does not carry.
        $otherPi = ProformaInvoice::factory()->create();
        $foreign = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $otherPi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $this->table($shipment)->assertCanNotSeeTableRecords([$remaining, $foreign]);
    }

    public function test_shipment_own_and_po_rows_keep_showing(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        $ownRow = PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => null,
            'label' => 'Freight: Air shipping cost',
            'due_condition' => null,
        ]);

        $po = PurchaseOrder::factory()->create(['supplier_company_id' => Company::factory()->create()->id]);
        $poRow = PaymentScheduleItemFactory::new()->create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'shipment_id' => $shipment->id,
            'label' => '30% — Before Shipment',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $this->table($shipment)->assertCanSeeTableRecords([$ownRow, $poRow]);
    }

    public function test_document_level_row_cannot_be_deleted_from_the_shipment_view(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        $docLevel = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        // The parcel belongs to the PI as a whole — deleting it from a shipment
        // that merely carries the PI would wipe a document-wide obligation, so
        // the action is not offered and the call is refused.
        $refused = false;

        try {
            $this->table($shipment)->callAction(TestAction::make('deleteItem')->table($docLevel));
        } catch (AssertionFailedError) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Delete must not be offered for a document-level parcel.');
        $this->assertDatabaseHas('payment_schedule_items', ['id' => $docLevel->id]);
    }

    public function test_ship_specific_row_stays_deletable_from_the_shipment_view(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        $shipSpecific = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'label' => '70% — Shipment Date',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
        ]);

        $this->table($shipment)
            ->callAction(TestAction::make('deleteItem')->table($shipSpecific));

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $shipSpecific->id]);
    }

    public function test_carried_pi_document_level_row_is_flagged_as_document_level(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $shipment = $this->shipmentCarrying($pi);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '100% — Order Date',
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);

        $this->table($shipment)
            ->assertSee(PaymentScheduleRelationManager::DOCUMENT_LEVEL_BADGE);
    }
}
