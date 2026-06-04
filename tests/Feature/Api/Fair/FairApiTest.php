<?php

namespace Tests\Feature\Api\Fair;

use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use App\Domain\TradeFairs\Models\TradeFair;
use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FairApiTest extends TestCase
{
    use RefreshDatabase;

    private function skipIfSqlite(): void
    {
        // GenerateProductSkuAction uses MySQL-only SUBSTRING_INDEX; the product
        // creation path can only be tested against MySQL (the production driver).
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Requires MySQL — Product SKU generator uses SUBSTRING_INDEX.');
        }
    }

    private function internalUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Internal Operator',
            'email' => 'ops@impex.test',
            'password' => bcrypt('password'),
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ], $overrides));
    }

    private function activeFair(): TradeFair
    {
        return TradeFair::create([
            'name' => 'Canton Fair Spring',
            'location' => 'Guangzhou',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'created_by' => $this->internalUser(['email' => 'creator@impex.test'])->id,
        ]);
    }

    private function category(string $name = 'Electronics'): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => str()->slug($name).'-'.uniqid(),
        ]);
    }

    public function test_login_succeeds_with_valid_internal_user(): void
    {
        $user = $this->internalUser();

        $response = $this->postJson('/api/fair/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'iPhone 15',
        ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->internalUser();

        $this->postJson('/api/fair/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
            'device_name' => 'iPhone 15',
        ])->assertStatus(422);
    }

    public function test_login_rejects_non_internal_user(): void
    {
        $clientCompany = Company::create(['name' => 'Acme Client', 'status' => CompanyStatus::ACTIVE]);
        $client = User::create([
            'name' => 'Client User',
            'email' => 'client@acme.test',
            'password' => bcrypt('password'),
            'type' => UserType::CLIENT,
            'status' => 'active',
            'company_id' => $clientCompany->id,
        ]);

        $this->postJson('/api/fair/v1/auth/login', [
            'email' => $client->email,
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ])->assertStatus(422);
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/fair/v1/trade-fairs/active')->assertStatus(401);
        $this->getJson('/api/fair/v1/reference-data')->assertStatus(401);
        $this->postJson('/api/fair/v1/fair-suppliers')->assertStatus(401);
    }

    public function test_active_trade_fairs_excludes_past_ones(): void
    {
        $creator = $this->internalUser(['email' => 'creator@impex.test']);

        $past = TradeFair::create([
            'name' => 'Last Year',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subMonths(11)->toDateString(),
            'created_by' => $creator->id,
        ]);

        $current = TradeFair::create([
            'name' => 'Canton Fair',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'created_by' => $creator->id,
        ]);

        Sanctum::actingAs($creator);

        $response = $this->getJson('/api/fair/v1/trade-fairs/active')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($current->id, $response->json('data.0.id'));
    }

    public function test_reference_data_returns_categories_and_currencies(): void
    {
        $this->category('Electronics');
        $this->category('Textiles');

        Sanctum::actingAs($this->internalUser());

        $this->getJson('/api/fair/v1/reference-data')
            ->assertOk()
            ->assertJsonStructure(['categories', 'currencies', 'countries'])
            ->assertJsonCount(2, 'categories');
    }

    public function test_company_search_matches_by_name_and_supplier_role(): void
    {
        $supplier = Company::create(['name' => 'Brightstar Electronics', 'status' => CompanyStatus::ACTIVE]);
        $supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        Company::create(['name' => 'Random Client', 'status' => CompanyStatus::ACTIVE]);

        Sanctum::actingAs($this->internalUser());

        $response = $this->postJson('/api/fair/v1/companies/search', ['q' => 'Brightstar'])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($supplier->id, $response->json('data.0.id'));
    }

    public function test_store_fair_supplier_creates_company_contact_product_with_photo(): void
    {
        $this->skipIfSqlite();
        Storage::fake('public');

        $fair = $this->activeFair();
        $category = $this->category();

        Sanctum::actingAs($this->internalUser(), ['fair:register']);

        $payload = [
            'trade_fair_id' => $fair->id,
            'company_name' => 'Shenzhen New Vendor Co.',
            'address_city' => 'Shenzhen',
            'address_country' => 'CN',
            'company_notes' => 'Met at booth 5C12',
            'contact_name' => 'Mr Wang',
            'contact_email' => 'wang@vendor.cn',
            'contact_phone' => '+86 138 0000 1234',
            'contact_wechat' => 'wang_vendor',
            'business_card_photo' => UploadedFile::fake()->image('card.jpg', 800, 500),
            'products' => [
                [
                    'name' => 'Bluetooth Speaker X1',
                    'category_id' => $category->id,
                    'unit_price' => 12.50,
                    'currency_code' => 'USD',
                    'moq' => 100,
                    'photo' => UploadedFile::fake()->image('speaker.jpg', 400, 400),
                ],
            ],
        ];

        $response = $this->postJson('/api/fair/v1/fair-suppliers', $payload)
            ->assertStatus(201)
            ->assertJsonStructure([
                'company' => ['id', 'name', 'trade_fair_id', 'photo_count'],
                'product_names',
                'reused_existing_company',
            ]);

        $companyId = $response->json('company.id');

        $this->assertDatabaseHas('companies', [
            'id' => $companyId,
            'name' => 'Shenzhen New Vendor Co.',
            'trade_fair_id' => $fair->id,
            'status' => CompanyStatus::PROSPECT->value,
        ]);

        $this->assertDatabaseHas('company_roles', [
            'company_id' => $companyId,
            'role' => CompanyRole::SUPPLIER->value,
        ]);

        $this->assertDatabaseHas('contacts', [
            'company_id' => $companyId,
            'name' => 'Mr Wang',
            'email' => 'wang@vendor.cn',
            'wechat' => 'wang_vendor',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('company_product', [
            'company_id' => $companyId,
            'currency_code' => 'USD',
            'moq' => 100,
        ]);

        // Legacy business_card_photo field is mapped to the company photo gallery.
        $this->assertSame(1, $response->json('company.photo_count'));

        $photoPath = \App\Domain\CRM\Models\CompanyPhoto::where('company_id', $companyId)
            ->where('is_primary', true)
            ->value('path');
        $this->assertNotNull($photoPath);
        Storage::disk('public')->assertExists($photoPath);
    }

    public function test_store_fair_supplier_creates_multiple_product_images(): void
    {
        $this->skipIfSqlite();
        Storage::fake('public');

        $fair = $this->activeFair();
        $category = $this->category();

        Sanctum::actingAs($this->internalUser(), ['fair:register']);

        $payload = [
            'trade_fair_id' => $fair->id,
            'company_name' => 'Multi Photo Vendor Co.',
            'address_country' => 'CN',
            'contact_name' => 'Ms Li',
            'products' => [
                [
                    'name' => 'Desk Lamp Pro',
                    'category_id' => $category->id,
                    'currency_code' => 'USD',
                    'photos' => [
                        UploadedFile::fake()->image('lamp-1.jpg', 400, 400),
                        UploadedFile::fake()->image('lamp-2.jpg', 400, 400),
                        UploadedFile::fake()->image('lamp-3.jpg', 400, 400),
                    ],
                ],
            ],
        ];

        $this->postJson('/api/fair/v1/fair-suppliers', $payload)->assertStatus(201);

        $product = \App\Domain\Catalog\Models\Product::where('name', 'Desk Lamp Pro')->firstOrFail();

        // Three gallery rows, exactly one primary, avatar mirrors the first.
        $this->assertSame(3, $product->images()->count());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());

        $first = $product->images()->orderBy('sort_order')->first();
        $this->assertTrue($first->is_primary);
        $this->assertSame($first->path, $product->avatar);

        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }

        // First photo is also mirrored onto the supplier-specific pivot avatar.
        $this->assertDatabaseHas('company_product', [
            'product_id' => $product->id,
            'avatar_path' => $first->path,
        ]);
    }

    public function test_store_fair_supplier_validation_fails_without_required_fields(): void
    {
        Sanctum::actingAs($this->internalUser());

        $this->postJson('/api/fair/v1/fair-suppliers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trade_fair_id']);
    }

    public function test_store_fair_supplier_reuses_existing_company(): void
    {
        $this->skipIfSqlite();
        Storage::fake('public');

        $fair = $this->activeFair();
        $category = $this->category();

        $existing = Company::create(['name' => 'Already Known Vendor', 'status' => CompanyStatus::ACTIVE]);
        $existing->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        Sanctum::actingAs($this->internalUser(), ['fair:register']);

        $response = $this->postJson('/api/fair/v1/fair-suppliers', [
            'trade_fair_id' => $fair->id,
            'existing_company_id' => $existing->id,
            'company_notes' => 'Updated at this fair',
            'products' => [
                [
                    'name' => 'New Product From Fair',
                    'category_id' => $category->id,
                    'unit_price' => 5.00,
                    'currency_code' => 'USD',
                ],
            ],
        ])->assertStatus(201);

        $this->assertTrue($response->json('reused_existing_company'));
        $this->assertSame($existing->id, $response->json('company.id'));

        // Existing company is linked to this fair and notes updated
        $existing->refresh();
        $this->assertSame($fair->id, $existing->trade_fair_id);
        $this->assertSame('Updated at this fair', $existing->notes);
    }
}
