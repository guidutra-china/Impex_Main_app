<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class InquiryItemsListColumnsTest extends TestCase
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

    public function test_items_list_renders_avatar_model_and_description_columns_with_client_pivot_priority(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $product = Product::factory()->create([
            'name' => 'Lat Pull Down',
            'sku' => 'GYM-00029',
            'model_number' => 'LT012',
            'description' => 'Catalog description',
        ]);
        $product->companies()->attach($client->id, [
            'role' => 'client',
            'external_code' => 'DPF-LT012',
            'external_description' => 'Client description',
        ]);

        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $product->id,
            'description' => 'DPF-LT012',
            'quantity' => 2,
            'unit' => 'pcs',
            'sort_order' => 1,
        ]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $inquiry,
            'pageClass' => EditInquiry::class,
        ]);

        $component
            ->assertSuccessful()
            ->assertCanRenderTableColumn('product.avatar')
            ->assertCanRenderTableColumn('model_no')
            ->assertCanRenderTableColumn('model_description')
            ->assertSee('DPF-LT012')
            ->assertSee('Client description')
            ->assertDontSee('Catalog description'); // pivot do cliente tem prioridade
    }
}
