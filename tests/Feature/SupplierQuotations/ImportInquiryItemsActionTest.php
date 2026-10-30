<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Filament\Resources\SupplierQuotations\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ImportInquiryItemsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_price_is_not_copied_to_supplier_cost_by_default(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $inquiry = Inquiry::factory()->create();
        InquiryItem::factory()->create(['inquiry_id' => $inquiry->id, 'target_price' => Money::toMinor(1.5)]);
        $sq = SupplierQuotation::factory()->create(['inquiry_id' => $inquiry->id]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $sq,
            'pageClass' => ViewSupplierQuotation::class,
        ])
            ->callTableAction('importInquiryItems')
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, $sq->items()->sole()->unit_cost);
    }
}
