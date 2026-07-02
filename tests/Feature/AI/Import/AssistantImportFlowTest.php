<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Targets\ImportTarget;
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

        $this->app->bind(DocumentClassifier::class, fn () => new class extends DocumentClassifier
        {
            public function __construct() {}

            public function classify(array $documentBlocks, array $targets): array
            {
                return ['tipo' => 'supplier_quotation', 'confianca' => 'alta', 'motivo' => 'teste'];
            }
        });
    }

    public function test_upload_then_confirm_creates_records(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        // Fake extractor → no API call; returns a canned draft.
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
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
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('importPreview.fornecedor.nome', 'Flow Supplier')
            ->call('confirmImport');

        $this->assertSame(1, SupplierQuotation::count());
        $this->assertDatabaseHas('companies', ['name' => 'Flow Supplier']);
    }

    public function test_chat_message_edits_the_active_preview(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        // Extractor returns an item with no unit (Resolve defaults it to 'pcs').
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        // Editor flips the unit to 'box' and replies.
        $this->app->bind(DraftEditor::class, fn () => new class extends DraftEditor
        {
            public function __construct() {}

            public function edit(ImportTarget $target, array $draft, string $instruction): array
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
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('importPreview.itens.0.unit', 'pcs') // default applied on extract
            ->set('draft', 'mude a unidade para box')
            ->call('send')
            ->assertSet('importPreview.itens.0.unit', 'box') // chat edit re-resolved the preview
            ->assertSee('Units updated.');
    }

    public function test_edited_form_is_used_on_confirm(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
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
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('form.itens.0.description', 'Item')
            ->set('form.itens.0.description', 'Edited Item')
            ->set('form.itens.0.quantity', 5)
            ->call('confirmImport');

        $sq = SupplierQuotation::first();
        $this->assertNotNull($sq);
        $item = $sq->items()->first();
        $this->assertSame('Edited Item', $item->description);
        $this->assertSame(5, (int) $item->quantity);
    }
}
