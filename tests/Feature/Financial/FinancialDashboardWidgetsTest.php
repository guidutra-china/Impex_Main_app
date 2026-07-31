<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Users\Enums\UserType;
use App\Filament\Widgets\Financial\FinancialKpisWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function adminActing(): User
    {
        $admin = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        return $admin;
    }

    private function openItem(string $payableType, int $payableId, int $amount, string $currency, string $dueDate, PaymentScheduleStatus $status = PaymentScheduleStatus::DUE): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => $currency,
            'due_date' => $dueDate,
            'status' => $status,
            'is_credit' => false,
        ]);
    }

    public function test_kpis_widget_shows_receivable_and_payable_totals(): void
    {
        $this->adminActing();

        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);
        $po = PurchaseOrder::factory()->create();

        $this->openItem(ProformaInvoice::class, $pi->id, 3_000_000, 'USD', now()->addDays(10)->toDateString());
        $this->openItem(PurchaseOrder::class, $po->id, 1_000_000, 'USD', now()->addDays(20)->toDateString());
        $this->openItem(ProformaInvoice::class, $pi->id, 500_000, 'USD', now()->subDays(5)->toDateString(), PaymentScheduleStatus::OVERDUE);

        Livewire::test(FinancialKpisWidget::class)
            ->assertSee('USD 350.00')   // receivables: 300 + 50
            ->assertSee('USD 100.00')   // payables
            ->assertSee('USD 50.00')    // overdue (the AR item past due)
            ->assertSee('USD 250.00');  // net: 350 - 100
    }
}
