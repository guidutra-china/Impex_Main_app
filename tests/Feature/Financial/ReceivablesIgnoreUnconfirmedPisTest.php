<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Finance\AccountsReceivable\Schemas\ReceivableForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PI em Sent é proposta ainda não aceita: suas parcelas, comissão e demais
 * custos não aparecem para alocação no recebimento. Só a partir de Confirmed.
 */
class ReceivablesIgnoreUnconfirmedPisTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);
    }

    /** @return array{0: PaymentScheduleItem, 1: PaymentScheduleItem, 2: PaymentScheduleItem} parcela, comissão, crédito */
    private function piWith(string $status): array
    {
        $pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id, 'status' => $status, 'currency_code' => 'USD']);

        $row = fn (array $extra) => PaymentScheduleItem::create($extra + [
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'percentage' => 0,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        $commission = AdditionalCost::create([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $pi->id,
            'cost_type' => AdditionalCostType::COMMISSION,
            'description' => 'Service Fee',
            'amount' => 500_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 500_000,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
        ]);

        // O custo pode ter criado a sua parcela sozinho; garante uma só.
        $commissionRow = PaymentScheduleItem::where('source_type', AdditionalCost::class)->where('source_id', $commission->id)->first()
            ?? $row(['label' => 'Service Fee', 'source_type' => AdditionalCost::class, 'source_id' => $commission->id]);

        return [
            $row(['label' => '30% — Order Date', 'percentage' => 30]),
            $commissionRow,
            $row(['label' => 'Discount', 'is_credit' => true, 'status' => PaymentScheduleStatus::PENDING->value]),
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function statuses(): array
    {
        return [
            'draft' => ['draft', false],
            'sent' => ['sent', false],
            'cancelled' => ['cancelled', false],
            'confirmed' => ['confirmed', true],
            'shipped' => ['shipped', true],
            'finalized' => ['finalized', true],
            'reopened' => ['reopened', true],
        ];
    }

    #[DataProvider('statuses')]
    public function test_installments_costs_and_credits_follow_the_pi_status(string $status, bool $listed): void
    {
        [$installment, $commission, $credit] = $this->piWith($status);

        $items = ReceivableForm::getCompanyScheduleItems($this->client->id, PaymentDirection::INBOUND)->pluck('id');
        $credits = ReceivableForm::getCompanyCreditItems($this->client->id, PaymentDirection::INBOUND)->pluck('id');

        $this->assertSame($listed, $items->contains($installment->id), 'parcela');
        $this->assertSame($listed, $items->contains($commission->id), 'comissão');
        $this->assertSame($listed, $credits->contains($credit->id), 'crédito');
    }
}
