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
use App\Filament\Resources\Finance\Concerns\HasPaymentAllocationPersistence;
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar um pagamento aprovado apaga e recria as alocações. O Debit Note
 * quitado por ele tem de voltar a ISSUED — senão fica PAID com a parcela em
 * aberto e some da lista de alocação de fornecedor (só lista DN ISSUED).
 */
class EditPaymentReleaseAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_releasing_allocations_reopens_supplier_debit_note(): void
    {
        $supplier = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        $debitNote = DebitNote::create([
            'company_id' => $supplier->id,
            'party_type' => PartyType::SUPPLIER->value,
            'total_amount' => 5_940_000,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
            'due_date' => '2026-07-01',
        ]);
        DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => 'Embroidery',
            'amount' => 5_940_000,
            'currency_code' => 'USD',
        ]);
        app(IssueDebitNoteAction::class)->execute($debitNote);

        $psi = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)->firstOrFail();

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $supplier->id,
            'amount' => 5_940_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-23',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => 5_940_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 5_940_000,
        ]);
        app(ApprovePaymentAction::class)->approve($payment);

        $this->assertSame(DebitNoteStatus::PAID, $debitNote->refresh()->status);

        $helper = new class
        {
            use HasPaymentAllocationPersistence;

            public function release($payment): void
            {
                $this->releaseAllocations($payment);
            }
        };
        $helper->release($payment->refresh());

        $this->assertSame(0, $payment->allocations()->count());
        $this->assertSame(PaymentScheduleStatus::DUE, $psi->refresh()->status);
        $this->assertSame(DebitNoteStatus::ISSUED, $debitNote->refresh()->status);

        $options = HasPaymentFormSections::getCompanyScheduleItems($supplier->id, PaymentDirection::OUTBOUND);
        $this->assertTrue($options->contains('id', $psi->id), 'DN volta à lista de alocação do fornecedor.');
    }
}
