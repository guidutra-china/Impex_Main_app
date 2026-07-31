<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\OpenItemsSnapshot;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Users\Enums\UserType;
use App\Filament\Widgets\Financial\FinancialKpisWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class OpenItemsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function openItem(ProformaInvoice $pi, int $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);
    }

    private function allocate(PaymentScheduleItem $item, int $amount, PaymentStatus $status): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $item->payable->company_id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => $status,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    public function test_snapshot_remaining_matches_per_item_accessor(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $item = $this->openItem($pi, 3_000_000);
        $this->allocate($item, 1_000_000, PaymentStatus::APPROVED);

        $snapshotItem = app(OpenItemsSnapshot::class)->receivables()->firstWhere('id', $item->id);
        $freshItem = PaymentScheduleItem::findOrFail($item->id);

        $this->assertNotNull($snapshotItem);
        $this->assertSame($freshItem->remaining_amount, $snapshotItem->remaining_amount);
        $this->assertSame(2_000_000, $snapshotItem->remaining_amount);
    }

    public function test_non_approved_payments_do_not_reduce_remaining(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $item = $this->openItem($pi, 3_000_000);
        $this->allocate($item, 1_000_000, PaymentStatus::PENDING_APPROVAL);
        $this->allocate($item, 500_000, PaymentStatus::REJECTED);
        $this->allocate($item, 250_000, PaymentStatus::CANCELLED);

        $snapshotItem = app(OpenItemsSnapshot::class)->receivables()->firstWhere('id', $item->id);
        $freshItem = PaymentScheduleItem::findOrFail($item->id);

        $this->assertSame(3_000_000, $snapshotItem->remaining_amount);
        $this->assertSame($freshItem->remaining_amount, $snapshotItem->remaining_amount);
    }

    public function test_allocation_queries_do_not_scale_with_item_count(): void
    {
        $admin = User::factory()->create(['type' => UserType::INTERNAL, 'status' => 'active']);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        foreach (range(1, 5) as $i) {
            $item = $this->openItem($pi, 1_000_000);
            $this->allocate($item, 100_000, PaymentStatus::APPROVED);
        }

        DB::enableQueryLog();
        Livewire::test(FinancialKpisWidget::class)->assertOk();
        $allocationQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'payment_allocations'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            2,
            $allocationQueries,
            "Expected at most one batched allocation query per direction, got {$allocationQueries}."
        );
    }

    public function test_receivables_is_memoized_per_request(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);
        $this->openItem($pi, 1_000_000);

        $snapshot = app(OpenItemsSnapshot::class);
        $first = $snapshot->receivables();

        DB::enableQueryLog();
        $second = $snapshot->receivables();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame(0, $queries, 'Second receivables() call must not re-query.');
    }

    public function test_snapshot_is_scoped_singleton(): void
    {
        $this->assertSame(app(OpenItemsSnapshot::class), app(OpenItemsSnapshot::class));
    }
}
