<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class BulkChangeExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        // 1 USD = 7 CNY → 1 CNY ≈ 0.142857 USD
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
        ]);
    }

    private function item(string $costCurrency, float $rate): \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem
    {
        return $this->pi->items()->create([
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 0,
            'unit_cost' => 10000, // 100.00 na moeda de custo
            'cost_currency_code' => $costCurrency,
            'cost_exchange_rate' => $rate,
            'sort_order' => 1,
        ]);
    }

    public function test_manual_rate_updates_selected_items_and_recomputes_doc_currency_cost(): void
    {
        $item = $this->item('CNY', 1 / 7.0);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->pi,
            'pageClass' => EditProformaInvoice::class,
        ])->callTableBulkAction('bulkChangeExchangeRate', [$item], [
            'mode' => 'manual',
            'cost_exchange_rate' => 0.2,
        ]);

        $item->refresh();

        $this->assertEqualsWithDelta(0.2, (float) $item->cost_exchange_rate, 0.00000001);
        $this->assertSame(2000, $item->unit_cost_in_document_currency); // 10000 * 0.2
        $this->assertSame(today()->toDateString(), $item->cost_exchange_rate_captured_at->toDateString());
    }

    public function test_items_costed_in_the_pi_currency_are_skipped(): void
    {
        $sameCurrency = $this->item('USD', 1.0);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->pi,
            'pageClass' => EditProformaInvoice::class,
        ])->callTableBulkAction('bulkChangeExchangeRate', [$sameCurrency], [
            'mode' => 'manual',
            'cost_exchange_rate' => 0.2,
        ]);

        $sameCurrency->refresh();

        $this->assertEqualsWithDelta(1.0, (float) $sameCurrency->cost_exchange_rate, 0.00000001);
        $this->assertSame(10000, $sameCurrency->unit_cost_in_document_currency);

        // O usuário precisa ver POR QUE nada mudou, não um "sucesso" de 0 itens.
        \Filament\Notifications\Notification::assertNotified(
            __('messages.fx_rate_no_items_updated')
        );
    }

    public function test_table_mode_resolves_the_rate_per_item_currency(): void
    {
        $item = $this->item('CNY', 0.5); // taxa fora da tabela, a ser corrigida

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->pi,
            'pageClass' => EditProformaInvoice::class,
        ])->callTableBulkAction('bulkChangeExchangeRate', [$item], [
            'mode' => 'table',
            'rate_date' => today()->toDateString(),
        ]);

        $item->refresh();

        $this->assertEqualsWithDelta(1 / 7.0, (float) $item->cost_exchange_rate, 0.0001);
        $this->assertSame(1429, $item->unit_cost_in_document_currency);
    }

    public function test_change_currency_bulk_action_accepts_a_manual_rate(): void
    {
        $item = $this->item('USD', 1.0);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->pi,
            'pageClass' => EditProformaInvoice::class,
        ])->callTableBulkAction('bulkChangeCurrency', [$item], [
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.15,
        ]);

        $item->refresh();

        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertEqualsWithDelta(0.15, (float) $item->cost_exchange_rate, 0.00000001);
        $this->assertSame(1500, $item->unit_cost_in_document_currency);
    }
}
