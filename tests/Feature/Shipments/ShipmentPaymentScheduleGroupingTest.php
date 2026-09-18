<?php

namespace Tests\Feature\Shipments;

use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\RelationManagers\PaymentScheduleRelationManager;
use App\Models\User;
use Database\Factories\PaymentScheduleItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aba de pagamentos do embarque agrupa as parcelas iguais (mesmo estágio,
 * lado e moeda) e abre recolhida: o cabeçalho mostra a soma, e expandir
 * revela as invoices que a compõem (SH-2026-00056: 30% de duas PIs).
 */
class ShipmentPaymentScheduleGroupingTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    /** @var array<string, PaymentScheduleItem> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->shipment = Shipment::factory()->create(['reference' => 'SH-2026-00056', 'currency_code' => 'USD']);
        $po = PurchaseOrder::factory()->create(['currency_code' => 'USD']);

        $sort = 0;
        $mirror = function (string $pi, string $label, CalculationBase $due, int $pct, int $amount, string $currency = 'USD') use (&$sort) {
            return $this->mirrorRow($pi, $label, $due, $pct, $amount, $currency, $sort += 7);
        };

        $this->rows['pi15_30'] = $mirror('PI-2026-00015', '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 437_375_000);
        $this->rows['pi17_30'] = $mirror('PI-2026-00017', '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 712_417_100);
        $this->rows['pi15_60'] = $mirror('PI-2026-00015', '60% — Delivery Date', CalculationBase::DELIVERY_DATE, 60, 874_750_000);
        $this->rows['eur_30'] = $mirror('PI-2026-00099', '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 100_000_000, 'EUR');

        // Fornecedor com sort_order INTERCALADO entre estágios (30, 60, 30) —
        // o caso real que quebrava o grupo em dois cabeçalhos.
        $poRow = fn (PurchaseOrder $order, string $label, CalculationBase $due, int $pct, int $sortOrder) => PaymentScheduleItemFactory::new()->create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $order->id,
            'shipment_id' => $this->shipment->id,
            'label' => $label.' — [SH-2026-00056 / '.$order->reference.']',
            'percentage' => $pct,
            'amount' => 437_375_000,
            'currency_code' => 'USD',
            'due_condition' => $due,
            'sort_order' => $sortOrder,
        ]);
        $po2 = PurchaseOrder::factory()->create(['currency_code' => 'USD']);

        $this->rows['po_30'] = $poRow($po, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 22);
        $this->rows['po_60'] = $poRow($po, '60% — Delivery Date', CalculationBase::DELIVERY_DATE, 60, 28);
        $this->rows['po2_30'] = $poRow($po2, '30% — Before Shipment', CalculationBase::BEFORE_SHIPMENT, 30, 46);

        $cost = fn (string $label, ?string $notes) => PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'shipment_id' => null,
            'source_type' => AdditionalCost::class,
            'source_id' => 1,
            'label' => $label,
            'percentage' => 0,
            'amount' => 328_000_000,
            'currency_code' => 'USD',
            'due_condition' => null,
            'notes' => $notes,
            // sort_order baixo: pela ordem da relação viriam ANTES das parcelas.
            'sort_order' => 1,
        ]);

        $this->rows['freight_in'] = $cost('Freight: Sea Freight', null);
        $this->rows['freight_out'] = $cost('Freight payable: Forwarder', PaymentScheduleItem::FORWARDER_PAYABLE_TAG);
    }

    private function mirrorRow(string $pi, string $label, CalculationBase $due, int $pct, int $amount, string $currency, int $sortOrder): PaymentScheduleItem
    {
        return PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'shipment_id' => $this->shipment->id,
            'label' => $label.' — [SH-2026-00056 / '.$pi.']',
            'percentage' => $pct,
            'amount' => $amount,
            'currency_code' => $currency,
            'due_condition' => $due,
            'sort_order' => $sortOrder,
        ]);
    }

    private function tab()
    {
        return Livewire::test(PaymentScheduleRelationManager::class, [
            'ownerRecord' => $this->shipment,
            'pageClass' => EditShipment::class,
        ])->assertSuccessful();
    }

    public function test_tab_is_grouped_by_stage_and_opens_collapsed(): void
    {
        $table = $this->tab()->instance()->getTable();

        $this->assertNotNull($table->getGrouping());
        $this->assertTrue($table->areGroupsCollapsedByDefault());
        $this->assertTrue($table->getGrouping()->isCollapsible());
    }

    public function test_equal_installments_share_a_group_while_side_currency_and_stage_split_them(): void
    {
        $group = $this->tab()->instance()->getTable()->getGrouping();
        $key = fn (string $row) => $group->getStringKey($this->rows[$row]->fresh());

        // Mesmo estágio, lado e moeda: um grupo só (as duas PIs do caso real).
        $this->assertSame($key('pi15_30'), $key('pi17_30'));

        // Estágio, lado do fornecedor e moeda diferentes não entram na mesma soma.
        $this->assertNotSame($key('pi15_30'), $key('pi15_60'));
        $this->assertNotSame($key('pi15_30'), $key('po_30'));
        $this->assertNotSame($key('pi15_30'), $key('eur_30'));

        // Custos: a receber e a pagar em grupos próprios.
        $this->assertNotSame($key('freight_in'), $key('freight_out'));
        $this->assertNotSame($key('freight_in'), $key('pi15_30'));
    }

    public function test_collapsed_header_shows_the_sum_and_how_many_installments_it_holds(): void
    {
        $group = $this->tab()->instance()->getTable()->getGrouping();
        $record = $this->rows['pi15_30']->fresh();
        $title = (string) $group->getTitle($record);
        $description = (string) $group->getDescription($record, $title);

        $this->assertStringContainsString('30% — Before Shipment', $title);
        // 43.737,50 + 71.241,71
        $this->assertStringContainsString('114,979.21', $description);
        $this->assertStringContainsString('USD', $description);
        $this->assertStringContainsString('2', $description);
    }

    public function test_group_titles_are_unique_so_collapse_state_never_leaks_between_groups(): void
    {
        $group = $this->tab()->instance()->getTable()->getGrouping();

        $titles = collect($this->rows)
            ->groupBy(fn (PaymentScheduleItem $row) => $group->getStringKey($row->fresh()))
            ->map(fn ($rows) => (string) $group->getTitle($rows->first()->fresh()));

        $this->assertSame($titles->count(), $titles->unique()->count(), $titles->implode(' | '));

        // Nenhuma chave de tradução crua no cabeçalho (forms.labels.…).
        foreach ($titles as $title) {
            $this->assertStringNotContainsString('forms.', $title);
        }
        $this->assertTrue($titles->contains(fn (string $t) => str_contains($t, 'Additional costs')));
    }

    public function test_document_level_group_says_so_in_its_header(): void
    {
        // Parcela de nível documento: valor cheio da PI, sem fatia por embarque.
        $pi = ProformaInvoice::factory()->create(['company_id' => $this->shipment->company_id]);
        $piItem = \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id, 'description' => 'Item', 'quantity' => 1, 'unit_price' => 1000, 'unit' => 'pcs',
        ]);
        \App\Domain\Logistics\Models\ShipmentItem::create([
            'shipment_id' => $this->shipment->id, 'proforma_invoice_item_id' => $piItem->id, 'quantity' => 1, 'sort_order' => 1,
        ]);
        $docLevel = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'label' => '10% — Order Date',
            'percentage' => 10,
            'amount' => 450_073_710_0,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'sort_order' => 1,
        ]);

        $group = $this->tab()->instance()->getTable()->getGrouping();

        $this->assertStringContainsString('Doc-level', (string) $group->getTitle($docLevel->fresh()));
        $this->assertStringNotContainsString('Doc-level', (string) $group->getTitle($this->rows['pi15_30']->fresh()));
    }

    public function test_scoping_by_key_returns_exactly_the_rows_of_the_group(): void
    {
        $component = $this->tab()->instance();
        $group = $component->getTable()->getGrouping();

        foreach (['pi15_30' => ['pi15_30', 'pi17_30'], 'po_30' => ['po_30', 'po2_30'], 'po_60' => ['po_60'], 'freight_out' => ['freight_out'], 'eur_30' => ['eur_30']] as $probe => $expected) {
            $key = $group->getStringKey($this->rows[$probe]->fresh());
            $ids = $group->scopeQueryByKey(PaymentScheduleItem::query(), $key)->pluck('id')->sort()->values()->all();

            $this->assertSame(
                collect($expected)->map(fn ($r) => $this->rows[$r]->id)->sort()->values()->all(),
                $ids,
                $probe,
            );
        }
    }

    public function test_rows_of_a_group_stay_contiguous_in_table_order(): void
    {
        $component = $this->tab()->instance();
        $group = $component->getTable()->getGrouping();

        $keys = $component->getTableRecords()->map(fn ($row) => $group->getStringKey($row))->values()->all();

        $runs = [];
        foreach ($keys as $key) {
            if (end($runs) !== $key) {
                $runs[] = $key;
            }
        }

        $this->assertSame(count(array_unique($keys)), count($runs), 'grupo quebrado em dois blocos: '.implode(' > ', $runs));
        // Cliente antes de fornecedor, custos por último.
        $this->assertStringStartsWith('client|', $keys[0]);
        $this->assertStringStartsWith('cost_', end($keys));

        // Dentro do lado, ordem da vida do pedido: 30% Before Shipment antes
        // do 60% Delivery Date (a ordem de declaração do enum é a inversa).
        $this->assertLessThan(
            array_search('client|60.00|delivery_date|USD', $runs, true),
            array_search('client|30.00|before_shipment|USD', $runs, true),
        );
    }

    public function test_every_due_condition_has_a_place_in_the_stage_chronology(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (CalculationBase $c) => $c->value, CalculationBase::cases()),
            array_map(fn (CalculationBase $c) => $c->value, PaymentScheduleRelationManager::STAGE_CHRONOLOGY),
        );
    }
}
