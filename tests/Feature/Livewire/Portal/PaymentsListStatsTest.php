<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Filament\Portal\Widgets\PaymentsListStats;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cards da página Pagamentos do portal: pagamentos em BRL eram somados aos
 * em USD e exibidos como "USD" (Deep Fitness, 2026-09-29). Cada moeda
 * aparece na sua própria linha.
 */
class PaymentsListStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_amounts_are_shown_per_currency(): void
    {
        Filament::setCurrentPanel('portal');
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));
        Filament::setTenant($company);

        foreach ([['USD', 10_000_000], ['USD', 5_000_000], ['BRL', 20_000_000]] as [$currency, $amount]) {
            Payment::create([
                'direction' => PaymentDirection::INBOUND,
                'company_id' => $company->id,
                'amount' => $amount,
                'currency_code' => $currency,
                'payment_date' => '2026-09-01',
                'status' => PaymentStatus::APPROVED,
            ]);
        }

        $data = (new \ReflectionMethod(PaymentsListStats::class, 'getViewData'))->invoke(new PaymentsListStats);

        $this->assertSame(['BRL 2,000.00', 'USD 1,500.00'], $data['totalAmount']);
        $this->assertSame(['BRL 2,000.00', 'USD 1,500.00'], $data['unallocatedAmount']);
        $this->assertSame(['BRL 0.00', 'USD 0.00'], $data['allocatedAmount']);
        $this->assertTrue($data['hasUnallocated']);
    }
}
