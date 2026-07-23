<?php

declare(strict_types=1);

namespace Tests\Feature\TradeFairs;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Actions\RegisterFairSupplierAction;
use App\Domain\TradeFairs\Actions\SyncFairCompanyAction;
use App\Domain\TradeFairs\DataTransferObjects\FairSupplierData;
use App\Domain\TradeFairs\Models\TradeFair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FairCompanyDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function fairData(string $companyName, ?string $clientUuid = null): FairSupplierData
    {
        $fair = TradeFair::create(['name' => 'Canton Fair', 'location' => 'Guangzhou']);

        return new FairSupplierData(
            tradeFairId: $fair->id,
            existingCompanyId: null,
            companyName: $companyName,
            addressCity: 'Shenzhen',
            addressCountry: 'CN',
            companyNotes: null,
            categoryIds: null,
            contactName: null,
            contactEmail: null,
            contactPhone: null,
            contactWechat: null,
            companyPhotos: [],
            products: [],
            clientUuid: $clientUuid,
        );
    }

    public function test_register_reuses_company_when_name_matches_ignoring_case(): void
    {
        $existing = Company::factory()->create(['name' => 'Foshan Deli Hardware Co., Ltd.']);

        $result = app(RegisterFairSupplierAction::class)->execute(
            $this->fairData('  foshan deli  hardware co., ltd.'),
        );

        $this->assertTrue($result->companyReused);
        $this->assertSame($existing->id, $result->company->id);
        $this->assertSame(1, Company::count());
        $this->assertTrue($existing->companyRoles()->where('role', CompanyRole::SUPPLIER->value)->exists());
    }

    public function test_sync_without_uuid_match_reuses_company_by_name_and_adopts_uuid(): void
    {
        $existing = Company::factory()->create([
            'name' => 'Foshan Deli Hardware Co., Ltd.',
            'client_uuid' => null,
        ]);

        $result = app(SyncFairCompanyAction::class)->execute(
            $this->fairData('FOSHAN DELI HARDWARE CO., LTD.', clientUuid: '11111111-2222-3333-4444-555555555555'),
        );

        $this->assertTrue($result->companyReused);
        $this->assertSame($existing->id, $result->company->id);
        $this->assertSame(1, Company::count());
        $this->assertSame('11111111-2222-3333-4444-555555555555', $existing->fresh()->client_uuid);
    }

    public function test_sync_does_not_steal_client_uuid_from_company_captured_on_another_device(): void
    {
        Company::factory()->create([
            'name' => 'Foshan Deli Hardware Co., Ltd.',
            'client_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $result = app(SyncFairCompanyAction::class)->execute(
            $this->fairData('Foshan Deli Hardware Co., Ltd.', clientUuid: '11111111-2222-3333-4444-555555555555'),
        );

        $this->assertTrue($result->companyReused);
        $this->assertSame(1, Company::count());
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result->company->fresh()->client_uuid);
    }
}
