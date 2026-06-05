<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ViewAccountsPayable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regression guard for payment approval authorization.
 *
 * The approve/reject permission lives in the action ->visible() closure. Filament
 * DOES enforce visibility server-side (isHidden() => isDisabled(), checked in both
 * mountAction() and callMountedAction()), so a hidden action cannot be executed
 * even via a crafted Livewire request that bypasses the test visibility assertion.
 *
 * These tests drive that lower-level mount/call path to prove the permission is
 * actually enforced (not merely a UI hint), and would fail if a future refactor
 * moved the permission out of ->visible() without an equivalent server-side guard.
 * No production change was required — the existing gating already holds.
 */
class PaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'approve-payments', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'reject-payments', 'guard_name' => 'web']);
        Filament::setCurrentPanel('admin');
    }

    private function pendingPayment(): Payment
    {
        return Payment::create([
            // AccountsPayableResource scopes to outbound payments.
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => Company::factory()->create()->id,
            'amount' => 10000000,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
    }

    private function actingAsUserDenied(array $denied): User
    {
        $user = User::factory()->create();
        Gate::before(function (User $u, string $ability) use ($user, $denied) {
            if ($u->id !== $user->id) {
                return null;
            }

            return in_array($ability, $denied) ? null : true;
        });
        $this->actingAs($user);

        return $user;
    }

    private function actingAsUserAllowed(): User
    {
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        return $user;
    }

    public function test_user_without_approve_permission_cannot_approve_via_crafted_request(): void
    {
        $this->actingAsUserDenied(['approve-payments']);
        $payment = $this->pendingPayment();

        Livewire::test(ViewAccountsPayable::class, ['record' => $payment->getRouteKey()])
            ->call('mountAction', 'approve')
            ->call('callMountedAction');

        $this->assertSame(
            PaymentStatus::PENDING_APPROVAL->value,
            $payment->fresh()->status->value,
            'A user without approve-payments must not be able to approve, even bypassing visibility.'
        );
    }

    public function test_user_with_approve_permission_can_approve(): void
    {
        $this->actingAsUserAllowed();
        $payment = $this->pendingPayment();

        Livewire::test(ViewAccountsPayable::class, ['record' => $payment->getRouteKey()])
            ->call('mountAction', 'approve')
            ->call('callMountedAction');

        $this->assertSame(
            PaymentStatus::APPROVED->value,
            $payment->fresh()->status->value,
            'A user with approve-payments approves the payment.'
        );
    }
}
