<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\EditSupplierQuotationDraft;
use App\Domain\AI\Import\SupplierQuotationExtractor;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Pages\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AssistantImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
        \Filament\Facades\Filament::setCurrentPanel('admin');
    }

    public function test_upload_then_confirm_creates_records(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        // Fake extractor → no API call; returns a canned draft.
        $this->app->bind(SupplierQuotationExtractor::class, fn () => new class extends SupplierQuotationExtractor
        {
            public function __construct() {}

            public function extract(array $documentBlocks, array $categoryNames = []): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [
                        ['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 50.0],
                    ],
                ];
            }
        });

        // Real tiny xlsx so DocumentExtractor works, wrapped in a Livewire-friendly fake upload.
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importPreview.fornecedor.nome', 'Flow Supplier')
            ->call('confirmImport');

        $this->assertSame(1, SupplierQuotation::count());
        $this->assertDatabaseHas('companies', ['name' => 'Flow Supplier']);
    }

    public function test_chat_message_edits_the_active_preview(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('view-assistant');
        $this->actingAs($user);

        // Extractor returns an item with no unit (Resolve defaults it to 'pcs').
        $this->app->bind(SupplierQuotationExtractor::class, fn () => new class extends SupplierQuotationExtractor
        {
            public function __construct() {}

            public function extract(array $documentBlocks, array $categoryNames = []): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        // Editor flips the unit to 'box' and replies.
        $this->app->bind(EditSupplierQuotationDraft::class, fn () => new class extends EditSupplierQuotationDraft
        {
            public function __construct() {}

            public function edit(array $draft, string $instruction, array $categoryNames = []): array
            {
                $draft['itens'][0]['unit'] = 'box';

                return ['draft' => $draft, 'reply' => 'Units updated.'];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importPreview.itens.0.unit', 'pcs') // default applied on extract
            ->set('draft', 'mude a unidade para box')
            ->call('send')
            ->assertSet('importPreview.itens.0.unit', 'box') // chat edit re-resolved the preview
            ->assertSee('Units updated.');
    }
}
