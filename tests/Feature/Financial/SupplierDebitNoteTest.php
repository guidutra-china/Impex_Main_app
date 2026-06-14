<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supplier-party Debit Note = an extra amount Impex owes the supplier
 * (a payable), surfacing in the OUTBOUND payment flow — the mirror of a
 * client Debit Note which is a receivable in the INBOUND flow.
 */
class SupplierDebitNoteTest extends TestCase
{
    use RefreshDatabase;

    /** Exposes the protected getCompanyScheduleItems via the trait. */
    private object $sections;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        $this->sections = new class
        {
            use HasPaymentFormSections;
        };
    }

    public function test_issuing_supplier_dn_creates_payable_schedule_items(): void
    {
        $dn = $this->issuedSupplierDebitNote([4_000_000]);

        $items = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $dn->id)
            ->get();

        $this->assertCount(1, $items);
        $this->assertFalse((bool) $items->first()->is_credit);
        $this->assertSame(4_000_000, (int) $items->sum('amount'));
    }

    public function test_supplier_dn_appears_in_outbound_allocation_not_inbound(): void
    {
        $dn = $this->issuedSupplierDebitNote([4_000_000]);

        $outbound = $this->sections::getCompanyScheduleItems($this->supplier->id, PaymentDirection::OUTBOUND);
        $inbound = $this->sections::getCompanyScheduleItems($this->supplier->id, PaymentDirection::INBOUND);

        $dnItemIds = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $dn->id)
            ->pluck('id');

        $this->assertTrue($outbound->pluck('id')->contains($dnItemIds->first()));
        $this->assertFalse($inbound->pluck('id')->contains($dnItemIds->first()));
    }

    public function test_paying_supplier_dn_settles_it(): void
    {
        $dn = $this->issuedSupplierDebitNote([4_000_000]);
        $psi = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $dn->id)
            ->firstOrFail();

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 4_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-12',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => 4_000_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 4_000_000,
        ]);

        // Pending payment: not settled yet.
        $this->assertSame(DebitNoteStatus::ISSUED, $dn->refresh()->status);

        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(PaymentScheduleStatus::PAID, $psi->refresh()->status);
        $this->assertSame(DebitNoteStatus::PAID, $dn->refresh()->status);
    }

    public function test_client_dn_still_only_inbound(): void
    {
        $client = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $client->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        $dn = DebitNote::create([
            'company_id' => $client->id,
            'party_type' => PartyType::CLIENT,
            'total_amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        DebitNoteLineItem::create([
            'debit_note_id' => $dn->id,
            'description' => 'Lab test repass',
            'amount' => 1_000_000,
            'currency_code' => 'USD',
        ]);
        app(IssueDebitNoteAction::class)->execute($dn);

        $psiId = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $dn->id)
            ->value('id');

        $inbound = $this->sections::getCompanyScheduleItems($client->id, PaymentDirection::INBOUND);
        $this->assertTrue($inbound->pluck('id')->contains($psiId));
    }

    public function test_reference_uses_max_plus_one_after_force_delete(): void
    {
        $year = now()->year;
        $first = $this->draftSupplierDebitNote([1_000_000]);
        $second = $this->draftSupplierDebitNote([1_000_000]);

        $this->assertSame(sprintf('DN-%d-0001', $year), $first->reference);
        $this->assertSame(sprintf('DN-%d-0002', $year), $second->reference);

        $first->forceDelete();

        $third = $this->draftSupplierDebitNote([1_000_000]);
        $this->assertSame(sprintf('DN-%d-0003', $year), $third->reference);
    }

    /**
     * @param  int[]  $lineAmounts
     */
    private function draftSupplierDebitNote(array $lineAmounts): DebitNote
    {
        $dn = DebitNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => array_sum($lineAmounts),
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);

        foreach ($lineAmounts as $i => $amount) {
            DebitNoteLineItem::create([
                'debit_note_id' => $dn->id,
                'description' => 'Reembolso '.($i + 1),
                'amount' => $amount,
                'currency_code' => 'USD',
            ]);
        }

        return $dn;
    }

    /**
     * @param  int[]  $lineAmounts
     */
    private function issuedSupplierDebitNote(array $lineAmounts): DebitNote
    {
        $dn = $this->draftSupplierDebitNote($lineAmounts);
        app(IssueDebitNoteAction::class)->execute($dn);

        return $dn->refresh();
    }
}
