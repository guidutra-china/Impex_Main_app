<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Inquiries\Actions\ImportInquiryAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImportInquiryActionTest extends TestCase
{
    use RefreshDatabase;

    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create-inquiries', 'edit-inquiries', 'create-companies'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
        $this->tempFile = tempnam(sys_get_temp_dir(), 'inq').'.xlsx';
        file_put_contents($this->tempFile, 'fake-bytes');
    }

    private function previewNova(array $overrides = []): array
    {
        return array_merge([
            'modo' => 'nova',
            'inquiry_id' => null,
            'cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => 'Cliente Novo SA'],
            'cabecalho' => ['currency_code' => 'USD', 'deadline' => null, 'notes' => null],
            'itens' => [
                ['status' => 'novo', 'product_id' => null, 'part_no' => null, 'description' => 'Widget', 'quantity' => 2, 'unit' => 'pcs', 'target_price_minor' => 5000, 'specifications' => null, 'notes' => null],
            ],
        ], $overrides);
    }

    public function test_new_mode_creates_client_inquiry_and_items(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-inquiries', 'create-companies']);

        $inquiry = app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);

        $this->assertDatabaseHas('companies', ['name' => 'Cliente Novo SA']);
        $client = Company::where('name', 'Cliente Novo SA')->first();
        $this->assertTrue($client->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists());

        $this->assertSame($client->id, $inquiry->company_id);
        $this->assertSame(InquiryStatus::RECEIVED, $inquiry->status);
        $this->assertSame(1, $inquiry->items()->count());
        $this->assertSame(5000, (int) $inquiry->items()->first()->target_price);

        // Source file attached.
        $this->assertSame(1, $inquiry->documents()->where('type', 'inquiry_source')->count());
    }

    public function test_new_mode_requires_create_inquiries_permission(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);
    }

    public function test_new_mode_with_new_client_requires_create_companies(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-inquiries');

        $this->expectException(AuthorizationException::class);
        app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);
    }

    public function test_existing_mode_appends_items_with_continued_sort_order(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-inquiries');

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);
        InquiryItem::create(['inquiry_id' => $inquiry->id, 'description' => 'Old', 'quantity' => 1, 'unit' => 'pcs', 'sort_order' => 4]);

        $result = app(ImportInquiryAction::class)(
            $this->previewNova(['modo' => 'existente', 'inquiry_id' => $inquiry->id]),
            $user,
            $this->tempFile,
        );

        $this->assertSame($inquiry->id, $result->id);
        $this->assertSame(2, $inquiry->items()->count());
        // `items()` already applies its own ascending orderBy('sort_order'), so chaining
        // orderByDesc() here would just add a losing tie-break clause; use max() instead.
        $this->assertSame(5, (int) $inquiry->items()->max('sort_order'));
    }

    public function test_existing_mode_rejects_closed_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-inquiries');
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::WON]);

        $this->expectException(\InvalidArgumentException::class);
        app(ImportInquiryAction::class)(
            $this->previewNova(['modo' => 'existente', 'inquiry_id' => $inquiry->id]),
            $user,
            $this->tempFile,
        );
    }

    public function test_rollback_after_file_copy_leaves_no_orphan_file(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-inquiries', 'create-companies']);

        // Fail the documents()->create() that runs right after the Storage::put,
        // forcing the transaction to roll back with the file already copied.
        Document::creating(fn () => throw new \RuntimeException('doc create failed'));

        try {
            app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('doc create failed', $e->getMessage());
        }

        $this->assertDatabaseCount('inquiries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_new_mode_without_client_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-inquiries', 'create-companies']);

        $this->expectException(\InvalidArgumentException::class);
        app(ImportInquiryAction::class)(
            $this->previewNova(['cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => '']]),
            $user,
            $this->tempFile,
        );
    }
}
