<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O antigo "Custom Price PDF" foi absorvido pelo Generate/Preview: as opções
 * de preço diferenciado vivem nos dois modais e o item separado saiu do menu.
 */
class PiDocumentsMenuPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->pi = ProformaInvoice::factory()->create();
    }

    public function test_generate_and_preview_offer_the_price_options(): void
    {
        foreach (['generatePdf', 'previewPdf'] as $action) {
            Livewire::test(ViewProformaInvoice::class, ['record' => $this->pi->getKey()])
                ->mountAction($action)
                ->assertFormFieldExists('use_custom_prices')
                ->assertFormFieldExists('use_formula')
                ->assertFormFieldExists('price_formula')
                ->assertFormFieldExists('save_as_custom_price');
        }
    }

    public function test_the_separate_custom_price_action_is_gone(): void
    {
        Livewire::test(ViewProformaInvoice::class, ['record' => $this->pi->getKey()])
            ->assertActionDoesNotExist('customPricePdf');
    }
}
