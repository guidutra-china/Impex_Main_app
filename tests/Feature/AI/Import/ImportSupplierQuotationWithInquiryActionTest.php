<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationWithInquiryAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImportSupplierQuotationWithInquiryActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create-supplier-quotations', 'create-companies', 'create-products', 'create-inquiries'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
    }

    private function userWithAllPermissions(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products', 'create-inquiries']);

        return $user;
    }

    private function preview(): array
    {
        return [
            'fornecedor' => ['status' => 'novo', 'company_id' => null, 'nome' => 'Hebei Yangrun Sports'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'EXW', 'lead_time_days' => null, 'moq' => null, 'valid_until' => null, 'supplier_reference' => 'YR-JJW-0612', 'notes' => null],
            'itens' => [
                [
                    'status' => 'novo', 'product_id' => null, 'part_no' => 'KB-8KG', 'description' => 'Cast Iron Kettlebell 8kg',
                    'quantity' => 20, 'unit' => 'pcs', 'unit_cost_minor' => 818, 'specifications' => null, 'moq' => null, 'lead_time_days' => null, 'notes' => null,
                ],
                [
                    'status' => 'novo', 'product_id' => null, 'part_no' => 'TRX-70', 'description' => 'Double-ended rope 70cm',
                    'quantity' => 10, 'unit' => 'pcs', 'unit_cost_minor' => 293, 'specifications' => 'nylon', 'moq' => null, 'lead_time_days' => null, 'notes' => null,
                ],
            ],
            'criar_inquiry' => true,
            'inquiry_company_id' => null, // passed explicitly to the action
        ];
    }

    private function fakeFile(): string
    {
        $path = storage_path('app/ai-imports-combined-test.pdf');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'fake');

        return $path;
    }

    public function test_creates_linked_inquiry_and_sq_pair_sharing_products(): void
    {
        $user = $this->userWithAllPermissions();
        $client = Company::factory()->create(['name' => 'Cristiano Andreghetto']);

        $result = (new ImportSupplierQuotationWithInquiryAction)($this->preview(), $user, $this->fakeFile(), [], $client->id);

        $sq = $result['sq'];
        $inquiry = $result['inquiry'];

        // SQ nasce vinculada à inquiry criada, com o cliente ESCOLHIDO (não o do documento).
        $this->assertSame($inquiry->id, $sq->inquiry_id);
        $this->assertSame($client->id, $inquiry->company_id);
        $this->assertSame('USD', $inquiry->currency_code);
        $this->assertTrue($client->fresh()->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists());

        // Cada SQ item tem seu InquiryItem espelho com o MESMO product_id (pré-requisito da PI).
        $this->assertSame(2, $inquiry->items()->count());
        foreach ($sq->items()->orderBy('sort_order')->get() as $sqItem) {
            $this->assertNotNull($sqItem->product_id);
            $this->assertNotNull($sqItem->inquiry_item_id);
            $inquiryItem = $inquiry->items()->whereKey($sqItem->inquiry_item_id)->first();
            $this->assertNotNull($inquiryItem);
            $this->assertSame($sqItem->product_id, $inquiryItem->product_id);
            $this->assertSame((int) $sqItem->quantity, (int) $inquiryItem->quantity);
            $this->assertNull($inquiryItem->target_price, 'supplier cost must not leak into the client target price');
        }
    }

    public function test_denies_without_create_inquiries_permission_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);
        $client = Company::factory()->create();

        try {
            (new ImportSupplierQuotationWithInquiryAction)($this->preview(), $user, $this->fakeFile(), [], $client->id);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
        }

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('supplier_quotations', 0);
    }

    public function test_unknown_client_fails_before_writing_anything(): void
    {
        $user = $this->userWithAllPermissions();

        try {
            (new ImportSupplierQuotationWithInquiryAction)($this->preview(), $user, $this->fakeFile(), [], 999999);
            $this->fail('Expected ModelNotFoundException');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('supplier_quotations', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_inner_failure_rolls_back_everything_including_stored_file(): void
    {
        $user = $this->userWithAllPermissions();
        $client = Company::factory()->create();

        $preview = $this->preview();
        $preview['itens'][0]['quantity'] = null; // NOT NULL column → insert blows up inside the inner action

        try {
            (new ImportSupplierQuotationWithInquiryAction)($preview, $user, $this->fakeFile(), [], $client->id);
            $this->fail('Expected a database-level exception');
        } catch (\Throwable) {
        }

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('supplier_quotations', 0);
        $this->assertDatabaseCount('inquiry_items', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('supplier-quotations'));
    }
}
