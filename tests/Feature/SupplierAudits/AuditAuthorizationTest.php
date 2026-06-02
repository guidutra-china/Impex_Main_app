<?php

namespace Tests\Feature\SupplierAudits;

use App\Domain\CRM\Models\Company;
use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\AuditType;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\ConductAudit;
use App\Filament\Resources\CRM\SupplierAudits\Pages\ViewSupplierAudit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Conducting and reviewing audits must be gated server-side by the
 * conduct-supplier-audits / review-supplier-audits permissions. Historically
 * these pages/actions only checked the audit status (->visible()), and Filament
 * does not enforce ->visible() at runtime in mountAction(), so the permissions
 * were effectively unenforced.
 */
class AuditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'conduct-supplier-audits', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'review-supplier-audits', 'guard_name' => 'web']);
        Filament::setCurrentPanel('admin');
    }

    private function audit(AuditStatus $status): SupplierAudit
    {
        return SupplierAudit::create([
            'company_id' => Company::factory()->create()->id,
            'reference' => 'AUD-'.uniqid(),
            'audit_type' => AuditType::INITIAL->value,
            'status' => $status->value,
            'scheduled_date' => now()->toDateString(),
        ]);
    }

    /** Allow every ability for this user EXCEPT the denied ones (which fall through to unset perms). */
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

    public function test_user_without_conduct_permission_cannot_open_conduct_page(): void
    {
        $this->actingAsUserDenied(['conduct-supplier-audits']);
        $audit = $this->audit(AuditStatus::SCHEDULED);

        Livewire::test(ConductAudit::class, ['record' => $audit->id])
            ->assertForbidden();

        $this->assertSame(
            AuditStatus::SCHEDULED->value,
            $audit->fresh()->status->value,
            'Unauthorized user must not start (IN_PROGRESS) the audit by opening Conduct.'
        );
    }

    public function test_user_with_conduct_permission_can_open_conduct_page(): void
    {
        $this->actingAsUserAllowed();
        $audit = $this->audit(AuditStatus::SCHEDULED);

        Livewire::test(ConductAudit::class, ['record' => $audit->id])
            ->assertSuccessful();

        $this->assertSame(
            AuditStatus::IN_PROGRESS->value,
            $audit->fresh()->status->value,
            'Authorized auditor opening Conduct advances SCHEDULED -> IN_PROGRESS.'
        );
    }

    public function test_user_without_review_permission_cannot_review_audit(): void
    {
        $this->actingAsUserDenied(['review-supplier-audits']);
        $audit = $this->audit(AuditStatus::COMPLETED);

        Livewire::test(ViewSupplierAudit::class, ['record' => $audit->id])
            ->callAction('review', data: [
                'result' => AuditResult::APPROVED->value,
                'corrective_actions' => null,
                'next_audit_date' => null,
            ]);

        $this->assertSame(
            AuditStatus::COMPLETED->value,
            $audit->fresh()->status->value,
            'Unauthorized user must not move the audit to REVIEWED.'
        );
    }

    public function test_user_with_review_permission_can_review_audit(): void
    {
        $this->actingAsUserAllowed();
        $audit = $this->audit(AuditStatus::COMPLETED);

        Livewire::test(ViewSupplierAudit::class, ['record' => $audit->id])
            ->callAction('review', data: [
                'result' => AuditResult::APPROVED->value,
                'corrective_actions' => null,
                'next_audit_date' => null,
            ]);

        $this->assertSame(
            AuditStatus::REVIEWED->value,
            $audit->fresh()->status->value,
            'Authorized reviewer moves the audit to REVIEWED.'
        );
    }
}
