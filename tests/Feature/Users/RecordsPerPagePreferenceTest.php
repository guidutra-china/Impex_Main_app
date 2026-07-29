<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Livewire\Admin\RecordsPerPageSelector;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class RecordsPerPagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $this->user->id ? true : null);
        $this->actingAs($this->user);
    }

    public function test_selector_saves_preference_on_change(): void
    {
        Livewire::test(RecordsPerPageSelector::class)
            ->assertSet('recordsPerPage', 25)
            ->set('recordsPerPage', 50);

        $this->assertSame(50, $this->user->refresh()->records_per_page);
    }

    public function test_selector_rejects_invalid_option(): void
    {
        $this->user->update(['records_per_page' => 25]);

        Livewire::test(RecordsPerPageSelector::class)
            ->set('recordsPerPage', 33)
            ->assertSet('recordsPerPage', 25);

        $this->assertSame(25, $this->user->refresh()->records_per_page);
    }

    public function test_tables_default_to_user_preference(): void
    {
        $this->user->update(['records_per_page' => 50]);

        Livewire::test(ListShipments::class)
            ->assertSet('tableRecordsPerPage', 50);
    }

    public function test_tables_fall_back_when_preference_not_in_options(): void
    {
        // 33 nunca está nas opções de paginação — a tabela mantém o padrão dela.
        $this->user->forceFill(['records_per_page' => 33])->save();

        Livewire::test(ListShipments::class)
            ->assertSet('tableRecordsPerPage', 10);
    }
}
