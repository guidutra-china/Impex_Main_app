<?php

namespace Tests\Feature\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Reports\PaymentAllocationExcelExporter;
use App\Domain\Financial\Reports\PaymentAllocationReport;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Portal\Resources\PaymentResource;
use App\Filament\Portal\Resources\PaymentResource\Pages\ListPayments;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lista de Pagamentos do Portal do Cliente: cada pagamento abre um modal com
 * onde ele foi alocado (PI, referência do cliente, embarque, BL…) e baixa uma
 * planilha simples com o mesmo conteúdo.
 */
class PortalPaymentAllocationDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private Shipment $shipment;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');
        Permission::firstOrCreate(['name' => 'portal:view-payments', 'guard_name' => 'web']);

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'PI-2026-09001',
            'client_reference' => 'CLI-PO-77',
            'currency_code' => 'USD',
        ]);
        $this->shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'SH-2026-09001',
            'bl_number' => 'LWSS26999',
            'client_reference' => null,
        ]);
        ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => ProformaInvoiceItem::factory()->create(['proforma_invoice_id' => $this->pi->id])->id,
            'quantity' => 1,
            'unit' => 'pcs',
        ]);

        $this->payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => 10_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-09-10',
            'reference' => 'SWIFT-ABC',
            'status' => PaymentStatus::APPROVED,
        ]);
    }

    private function stage(array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->create(array_merge([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function allocate(PaymentScheduleItem $item, int $amount, array $overrides = []): void
    {
        PaymentAllocation::create(array_merge([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => $amount,
            'allocated_amount_in_document_currency' => $amount,
        ], $overrides));
    }

    private function actingAsPortalUser(): void
    {
        $user = User::factory()->create(['company_id' => $this->client->id]);
        $user->givePermissionTo('portal:view-payments');
        $this->actingAs($user);
        Filament::setTenant($this->client);
    }

    public function test_each_allocation_carries_pi_client_reference_shipment_and_bl(): void
    {
        $this->allocate($this->stage(['label' => '30% — Order Date']), 3_000_000);
        $this->allocate($this->stage([
            'label' => '70% — Before Shipment — [SH-2026-09001 / PI-2026-09001]',
            'shipment_id' => $this->shipment->id,
        ]), 5_000_000);
        // Frete cobrado no embarque: a PI e a referência do cliente vêm das
        // PIs que o embarque carrega.
        $this->allocate($this->stage([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Freight: Ocean Freight',
        ]), 1_500_000);

        $report = PaymentAllocationReport::forPayment($this->payment->fresh());

        $this->assertSame(
            [
                ['PI-2026-09001', 'PI-2026-09001', 'CLI-PO-77', null, null, '30% — Order Date', 3_000_000],
                ['PI-2026-09001', 'PI-2026-09001', 'CLI-PO-77', 'SH-2026-09001', 'LWSS26999', '70% — Before Shipment', 5_000_000],
                ['SH-2026-09001', 'PI-2026-09001', 'CLI-PO-77', 'SH-2026-09001', 'LWSS26999', 'Freight: Ocean Freight', 1_500_000],
            ],
            array_map(fn (array $row) => [
                $row['document_ref'], $row['pi_ref'], $row['client_reference'],
                $row['shipment_ref'], $row['bl_number'], $row['description'], $row['allocated'],
            ], $report['rows']),
        );
        $this->assertSame(['allocated' => 9_500_000, 'unallocated' => 500_000, 'credits' => 0], $report['totals']);
        $this->assertFalse($report['has_exchange']);
        $this->assertFalse($report['show_document_amount']);
    }

    public function test_shipment_cost_is_split_across_its_pis_by_the_product_value_shipped(): void
    {
        // Embarque do setUp já carrega 1 item da PI-2026-09001; aqui ele
        // passa a levar 300 da primeira PI e 100 + 600 de uma segunda.
        $this->shipment->items()->delete();
        $second = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'PI-2026-09002',
            'currency_code' => 'USD',
        ]);
        foreach ([[$this->pi, 3, 1_000_000], [$second, 1, 1_000_000], [$second, 2, 3_000_000]] as [$pi, $quantity, $unitPrice]) {
            ShipmentItem::create([
                'shipment_id' => $this->shipment->id,
                'proforma_invoice_item_id' => ProformaInvoiceItem::factory()->create([
                    'proforma_invoice_id' => $pi->id,
                    // A PI tem mais que o embarcado: só o embarcado pesa no rateio.
                    'quantity' => 50,
                    'unit_price' => $unitPrice,
                ])->id,
                'quantity' => $quantity,
                'unit' => 'pcs',
            ]);
        }

        $freight = $this->stage([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Freight: Sea Freight',
        ]);
        $this->allocate($freight, 1_000_000);
        $this->allocate($this->stage(['label' => '30% — Order Date']), 500_000);

        $report = PaymentAllocationReport::forPayment($this->payment->fresh());

        // 300 de 1.000 embarcados = 30%; 700 de 1.000 = 70%.
        $this->assertSame(
            [
                ['reference' => 'PI-2026-09001', 'amount' => 300_000, 'currency' => 'USD', 'share_percent' => 30.0],
                ['reference' => 'PI-2026-09002', 'amount' => 700_000, 'currency' => 'USD', 'share_percent' => 70.0],
            ],
            $report['rows'][0]['pi_breakdown'],
        );
        $this->assertSame([], $report['rows'][1]['pi_breakdown'], 'parcela de PI não tem rateio');

        $this->actingAsPortalUser();
        Livewire::test(ListPayments::class)
            ->mountTableAction('viewAllocations', $this->payment)
            ->assertMountedActionModalSee(['PI-2026-09001', 'USD 30.00', 'PI-2026-09002', 'USD 70.00']);

        $path = (new PaymentAllocationExcelExporter)->export($this->payment->fresh());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false);
        @unlink($path);
        $header = collect($rows)->search(fn (array $row) => $row[0] === '#');
        $this->assertSame("PI-2026-09001: USD 30.00\nPI-2026-09002: USD 70.00", $rows[$header + 1][3]);
        $this->assertSame('PI-2026-09001', $rows[$header + 2][3]);
    }

    public function test_split_always_adds_up_to_the_allocated_amount(): void
    {
        $this->shipment->items()->delete();
        foreach (['PI-2026-09011', 'PI-2026-09012', 'PI-2026-09013'] as $reference) {
            $pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id, 'reference' => $reference]);
            ShipmentItem::create([
                'shipment_id' => $this->shipment->id,
                'proforma_invoice_item_id' => ProformaInvoiceItem::factory()->create([
                    'proforma_invoice_id' => $pi->id,
                    'quantity' => 1,
                    'unit_price' => 1_000_000,
                ])->id,
                'quantity' => 1,
                'unit' => 'pcs',
            ]);
        }

        // 100,00 em três partes iguais: 33,34 + 33,33 + 33,33.
        $this->allocate($this->stage([
            'payable_type' => Shipment::class,
            'payable_id' => $this->shipment->id,
            'label' => 'Freight: Sea Freight',
        ]), 1_000_000);

        $amounts = array_column(PaymentAllocationReport::forPayment($this->payment->fresh())['rows'][0]['pi_breakdown'], 'amount');

        $this->assertSame(1_000_000, array_sum($amounts));
        $this->assertEqualsCanonicalizing([333_400, 333_300, 333_300], $amounts);
    }

    public function test_exchange_and_applied_credit_expose_the_amount_in_document_currency(): void
    {
        $brl = $this->stage(['currency_code' => 'BRL', 'label' => '100% — Order Date']);
        $this->allocate($brl, 1_000_000, [
            'exchange_rate' => '5.50000000',
            'allocated_amount_in_document_currency' => 5_500_000,
        ]);

        $target = $this->stage(['label' => '70% — Before Shipment']);
        $credit = $this->stage(['label' => 'Discount: Factory discount 2%', 'is_credit' => true]);
        $this->allocate($target, 0, [
            'credit_schedule_item_id' => $credit->id,
            'allocated_amount_in_document_currency' => 200_000,
        ]);

        $report = PaymentAllocationReport::forPayment($this->payment->fresh());

        $this->assertTrue($report['has_exchange']);
        $this->assertTrue($report['show_document_amount']);
        $this->assertSame(5.5, $report['rows'][0]['exchange_rate']);
        $this->assertSame(5_500_000, $report['rows'][0]['document_amount']);
        $this->assertSame('BRL', $report['rows'][0]['document_currency']);

        // Crédito não movimenta dinheiro: 0 alocado, valor só no documento.
        $this->assertTrue($report['rows'][1]['is_credit']);
        $this->assertSame(0, $report['rows'][1]['allocated']);
        $this->assertSame(200_000, $report['rows'][1]['document_amount']);
        $this->assertNull($report['rows'][1]['exchange_rate']);
        $this->assertStringContainsString('Discount: Factory discount 2%', $report['rows'][1]['description']);
        $this->assertSame(['allocated' => 1_000_000, 'unallocated' => 9_000_000, 'credits' => 200_000], $report['totals']);
    }

    public function test_modal_on_the_payments_list_shows_the_allocation_details(): void
    {
        $this->actingAsPortalUser();
        $this->allocate($this->stage([
            'label' => '70% — Before Shipment — [SH-2026-09001 / PI-2026-09001]',
            'shipment_id' => $this->shipment->id,
        ]), 5_000_000);

        Livewire::test(ListPayments::class)
            ->assertTableActionVisible('viewAllocations', $this->payment)
            ->mountTableAction('viewAllocations', $this->payment)
            ->assertMountedActionModalSee([
                'PI-2026-09001',
                'CLI-PO-77',
                'SH-2026-09001',
                'LWSS26999',
                '70% — Before Shipment',
                'USD 500.00',
            ]);
    }

    public function test_modal_without_allocations_shows_the_empty_state(): void
    {
        $this->actingAsPortalUser();

        Livewire::test(ListPayments::class)
            ->mountTableAction('viewAllocations', $this->payment)
            ->assertMountedActionModalSee(__('widgets.portal.no_allocations_yet'));
    }

    public function test_spreadsheet_download_has_the_same_rows_as_the_modal(): void
    {
        $this->actingAsPortalUser();
        $this->allocate($this->stage([
            'label' => '70% — Before Shipment — [SH-2026-09001 / PI-2026-09001]',
            'shipment_id' => $this->shipment->id,
        ]), 5_000_000);

        Livewire::test(ListPayments::class)
            ->callTableAction('downloadAllocations', $this->payment)
            ->assertFileDownloaded(PaymentAllocationExcelExporter::filename($this->payment));

        $path = (new PaymentAllocationExcelExporter)->export($this->payment->fresh());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false);
        @unlink($path);

        $header = collect($rows)->search(fn (array $row) => $row[0] === '#');
        $this->assertNotFalse($header);
        $this->assertSame(
            ['#', __('widgets.portal.document'), __('widgets.portal.reference'), 'PI', __('forms.labels.client_reference'),
                __('widgets.portal.allocation_details.shipment'), __('widgets.portal.allocation_details.bl'),
                __('widgets.portal.schedule_item'), __('widgets.portal.allocated_amount').' (USD)'],
            $rows[$header],
        );
        $this->assertSame(
            [1, __('navigation.models.proforma_invoice'), 'PI-2026-09001', 'PI-2026-09001', 'CLI-PO-77',
                'SH-2026-09001', 'LWSS26999', '70% — Before Shipment', 500.0],
            array_map(fn ($v) => is_float($v) || is_int($v) ? $v : (string) $v, $rows[$header + 1]),
        );
        $this->assertSame(500.0, (float) $rows[$header + 2][8], 'total row sums the allocated column');
        $this->assertSame(1000.0, (float) $rows[3][1], 'payment amount in the summary block');
    }

    public function test_spreadsheet_can_also_be_downloaded_from_inside_the_modal(): void
    {
        $this->actingAsPortalUser();
        $this->allocate($this->stage(), 5_000_000);

        Livewire::test(ListPayments::class)
            ->callAction([
                TestAction::make('viewAllocations')->table($this->payment),
                TestAction::make('downloadAllocationsFromModal'),
            ])
            ->assertFileDownloaded(PaymentAllocationExcelExporter::filename($this->payment));
    }

    public function test_payment_of_another_company_is_not_reachable_from_the_list(): void
    {
        // Criado antes de o tenant entrar em cena: com o painel inicializado
        // o Filament grava a empresa do tenant em todo registro novo.
        $foreign = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => Company::factory()->create()->id,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-09-10',
            'status' => PaymentStatus::APPROVED,
        ]);

        $this->actingAsPortalUser();
        // O escopo de tenant é registrado no boot do painel, que em produção
        // roda no middleware; no teste Livewire precisa ser chamado à mão.
        Filament::bootCurrentPanel();

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$this->payment])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertTableActionVisible('viewAllocations', $this->payment);

        // Fora da lista, as ações da linha não alcançam o pagamento alheio.
        $this->assertNull(PaymentResource::getEloquentQuery()->find($foreign->id));
    }
}
