<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use App\Filament\Resources\PurchaseOrders\Widgets\PurchaseOrderStats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Espelho admin do fix do Portal (PI-2026-00070 / PI-2026-00083): crédito já
 * aplicado numa parcela entra no paid_amount dela e também era abatido do
 * total devido, então o Financial Summary mostrava saldo menor que o real.
 */
class AdminStatsAppliedCreditTest extends TestCase
{
    use RefreshDatabase;

    private function stage(Model $payable, int $amount, array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::factory()->create(array_merge([
            'payable_type' => get_class($payable),
            'payable_id' => $payable->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE,
            'sort_order' => $payable->paymentScheduleItems()->count() + 1,
        ], $overrides));
    }

    private function payment(Model $payable, int $amount): Payment
    {
        $isPurchaseOrder = $payable instanceof PurchaseOrder;

        return Payment::create([
            'direction' => $isPurchaseOrder ? PaymentDirection::OUTBOUND : PaymentDirection::INBOUND,
            'company_id' => $isPurchaseOrder ? $payable->supplier_company_id : $payable->company_id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
    }

    private function allocate(PaymentScheduleItem $psi, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $this->payment($psi->payable, $amount)->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function applyCredit(PaymentScheduleItem $target, PaymentScheduleItem $credit, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $this->payment($target->payable, 0)->id,
            'payment_schedule_item_id' => $target->id,
            'credit_schedule_item_id' => $credit->id,
            'allocated_amount' => 0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function viewData(object $widget, Model $record): array
    {
        $widget->record = $record->fresh();

        return (new \ReflectionMethod($widget, 'getViewData'))->invoke($widget);
    }

    private function card(array $data, string $labelKey): ?array
    {
        return collect($data['cards'])->firstWhere('label', __($labelKey));
    }

    private function pi(): ProformaInvoice
    {
        return ProformaInvoice::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
        ]);
    }

    public function test_admin_pi_stats_do_not_count_applied_discount_twice(): void
    {
        $pi = $this->pi();
        $products = $this->stage($pi, 10_000_000);
        $this->stage($pi, 500_000, ['label' => 'Commission: 5%']);
        $discount = $this->stage($pi, 200_000, ['label' => 'Discount: factory', 'is_credit' => true]);

        $this->allocate($products, 9_800_000);
        $this->applyCredit($products, $discount, 200_000);

        $data = $this->viewData(new ProformaInvoiceStats, $pi);

        $this->assertSame(500_000, $data['totals']['remaining_raw'], 'open balance = the unpaid commission');
        $this->assertSame('1,030.00', $data['totals']['amount']);
        $this->assertSame('980.00', $data['totals']['paid']);
        $this->assertSame('USD 980.00', $this->card($data, 'widgets.document_summary.paid')['value']);
        $this->assertSame('USD 50.00', $this->card($data, 'widgets.document_summary.remaining')['value']);
        $this->assertSame(95, $data['progress']);
    }

    public function test_admin_pi_stats_count_credit_note_applied_to_a_stage_as_paid(): void
    {
        // PI-2026-00052: o crédito vem de uma Credit Note, fora do cronograma
        // da PI. Nada o abate do total, então na PI ele vale como pagamento.
        $pi = $this->pi();
        $products = $this->stage($pi, 10_000_000);
        $this->stage($pi, 500_000, ['label' => 'Commission: 5%']);
        $creditNoteStage = PaymentScheduleItem::factory()->credit()->create([
            'payable_type' => CreditNote::class,
            'payable_id' => 999,
            'amount' => 600_000,
            'currency_code' => 'USD',
        ]);

        $this->allocate($products, 9_400_000);
        $this->applyCredit($products, $creditNoteStage, 600_000);

        $data = $this->viewData(new ProformaInvoiceStats, $pi);

        $this->assertSame(500_000, $data['totals']['remaining_raw']);
        $this->assertSame('1,050.00', $data['totals']['amount']);
        $this->assertSame('1,000.00', $data['totals']['paid']);
        $this->assertSame(95, $data['progress']);
    }

    public function test_admin_pi_stats_keep_unapplied_discount_reducing_the_balance(): void
    {
        $pi = $this->pi();
        $products = $this->stage($pi, 10_000_000);
        $this->stage($pi, 200_000, ['label' => 'Discount: factory', 'is_credit' => true]);

        $this->allocate($products, 3_000_000);

        $data = $this->viewData(new ProformaInvoiceStats, $pi);

        $this->assertSame(6_800_000, $data['totals']['remaining_raw']);
        $this->assertSame('300.00', $data['totals']['paid']);
    }

    public function test_admin_po_stats_do_not_count_applied_supplier_credit_twice(): void
    {
        $po = PurchaseOrder::factory()->create(['currency_code' => 'USD']);
        $deposit = $this->stage($po, 3_000_000);
        $this->stage($po, 7_000_000);
        $credit = $this->stage($po, 200_000, ['label' => 'Discount: supplier', 'is_credit' => true]);

        $this->allocate($deposit, 2_800_000);
        $this->applyCredit($deposit, $credit, 200_000);

        $data = $this->viewData(new PurchaseOrderStats, $po);

        $this->assertSame(7_000_000, $data['totals']['remaining_raw'], 'open balance = the unpaid 70% stage');
        $this->assertSame('980.00', $data['totals']['amount']);
        $this->assertSame('280.00', $data['totals']['paid']);
        $this->assertSame('USD 280.00', $this->card($data, 'widgets.document_summary.paid')['value']);
        $this->assertSame(29, $data['progress']);
    }
}
