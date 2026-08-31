<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentFinancialStatementActionsTest extends TestCase
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

    public function test_financial_statement_actions_are_registered_on_the_shipment_header(): void
    {
        $shipment = Shipment::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSuccessful()
            ->assertActionExists('generateFinancialStatementPdf')
            ->assertActionExists('downloadFinancialStatementPdf')
            ->assertActionExists('previewFinancialStatementPdf')
            ->assertActionExists('sendFinancialStatementByEmail');
    }

    public function test_company_settings_expose_the_financial_statement_email_message(): void
    {
        $this->assertTrue(
            property_exists(CompanySettings::class, 'email_default_message_financial_statement'),
            'SendDocumentByEmailAction usa property_exists; sem a propriedade a mensagem padrão some.',
        );
    }
}
