<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ListAccountsPayable;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AR/AP list pages expose an "Alocações" row shortcut that deep-links to
 * the View page with the manageAllocations modal opened via ?action=.
 */
class ListAllocationsShortcutTest extends TestCase
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

    private function payment(PaymentDirection $direction, PaymentStatus $status): Payment
    {
        return Payment::create([
            'direction' => $direction,
            'company_id' => Company::factory()->create()->id,
            'amount' => 10000000,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_payables_list_shows_allocations_shortcut_linking_to_view_modal(): void
    {
        $payment = $this->payment(PaymentDirection::OUTBOUND, PaymentStatus::APPROVED);

        Livewire::test(ListAccountsPayable::class)
            ->assertActionVisible(TestAction::make('allocations')->table($payment))
            ->assertActionHasUrl(
                TestAction::make('allocations')->table($payment),
                AccountsPayableResource::getUrl('view', [
                    'record' => $payment,
                    'action' => 'manageAllocations',
                ]),
            );
    }

    public function test_payables_list_hides_allocations_shortcut_for_unapproved_payment(): void
    {
        $payment = $this->payment(PaymentDirection::OUTBOUND, PaymentStatus::PENDING_APPROVAL);

        Livewire::test(ListAccountsPayable::class)
            ->assertActionHidden(TestAction::make('allocations')->table($payment));
    }

    /**
     * The ?action= deep link mounts via wire:init in the browser (Filament
     * core); here we prove the target action is mountable server-side for an
     * approved payment with unallocated balance.
     */
    public function test_view_page_can_mount_allocations_modal_for_approved_payment(): void
    {
        $payment = $this->payment(PaymentDirection::OUTBOUND, PaymentStatus::APPROVED);

        Livewire::test(
            \App\Filament\Resources\Finance\AccountsPayable\Pages\ViewAccountsPayable::class,
            ['record' => $payment->getRouteKey()],
        )
            ->call('mountAction', 'manageAllocations')
            ->assertActionMounted('manageAllocations');
    }

    public function test_receivables_list_shows_allocations_shortcut_linking_to_view_modal(): void
    {
        $payment = $this->payment(PaymentDirection::INBOUND, PaymentStatus::APPROVED);

        Livewire::test(ListAccountsReceivable::class)
            ->assertActionVisible(TestAction::make('allocations')->table($payment))
            ->assertActionHasUrl(
                TestAction::make('allocations')->table($payment),
                AccountsReceivableResource::getUrl('view', [
                    'record' => $payment,
                    'action' => 'manageAllocations',
                ]),
            );
    }
}
