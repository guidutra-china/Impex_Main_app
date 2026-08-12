<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Settings\Currencies\CurrencyResource;
use App\Filament\Resources\Settings\Currencies\Pages\EditCurrency;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * All Edit pages share HasSaveAndReturnFormActions: the primary button
 * ("Salvar e Retornar") saves and goes back to the list; the secondary
 * "Salvar" saves and opens the record's View page (hidden when the
 * resource has no View page).
 */
class EditSaveAndReturnActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        \App\Domain\Settings\Models\Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'name_plural' => 'US Dollars',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
    }

    private function inquiry(): Inquiry
    {
        $company = Company::factory()->create();
        $company->companyRoles()->create(['role' => \App\Domain\CRM\Enums\CompanyRole::CLIENT->value]);

        return Inquiry::factory()->create([
            'company_id' => $company->id,
            'currency_code' => 'USD',
        ]);
    }

    public function test_primary_save_redirects_to_index(): void
    {
        $inquiry = $this->inquiry();

        Livewire::test(EditInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InquiryResource::getUrl('index'));
    }

    public function test_save_and_view_saves_and_redirects_to_view_page(): void
    {
        $inquiry = $this->inquiry();

        Livewire::test(EditInquiry::class, ['record' => $inquiry->getRouteKey()])
            ->fillForm(['description' => 'Updated via Salvar'])
            ->call('saveAndView')
            ->assertHasNoFormErrors()
            ->assertRedirect(InquiryResource::getUrl('view', ['record' => $inquiry]));

        $this->assertSame('Updated via Salvar', $inquiry->fresh()->description);
    }

    public function test_save_and_view_is_hidden_when_resource_has_no_view_page(): void
    {
        $this->assertFalse(CurrencyResource::hasPage('view'));

        $currency = \App\Domain\Settings\Models\Currency::where('code', 'USD')->first();

        $component = Livewire::test(EditCurrency::class, ['record' => $currency->getRouteKey()]);

        $saveAndView = collect(
            (new \ReflectionMethod($component->instance(), 'getFormActions'))->invoke($component->instance())
        )->first(fn ($action) => $action->getName() === 'saveAndView');

        $this->assertNotNull($saveAndView);
        $this->assertTrue($saveAndView->isHidden());
    }
}
