<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\FindPreviousImportsOfFileAction;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
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

class DuplicateImportDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products', 'create-inquiries'] as $name) {
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

    private function tempFileWithContent(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dup').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function attachSourceDocument(SupplierQuotation $sq, string $bytes, string $type = 'supplier_quotation_source'): void
    {
        $sq->documents()->create([
            'type' => $type,
            'name' => 'source.xlsx',
            'disk' => 'local',
            'path' => 'documents/source.xlsx',
            'source' => DocumentSourceType::UPLOADED,
            'checksum' => hash('sha256', $bytes),
            'size' => strlen($bytes),
        ]);
    }

    public function test_finds_previous_import_with_same_file_checksum(): void
    {
        $sq = SupplierQuotation::factory()->create();
        $this->attachSourceDocument($sq, 'same-bytes');

        $matches = app(FindPreviousImportsOfFileAction::class)
            ->execute($this->tempFileWithContent('same-bytes'));

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->documentable->is($sq));
    }

    public function test_ignores_different_checksums_and_non_source_documents(): void
    {
        $sq = SupplierQuotation::factory()->create();
        $this->attachSourceDocument($sq, 'other-bytes');
        $this->attachSourceDocument($sq, 'same-bytes', type: 'attachment');

        $matches = app(FindPreviousImportsOfFileAction::class)
            ->execute($this->tempFileWithContent('same-bytes'));

        $this->assertCount(0, $matches);
    }

    public function test_ignores_documents_of_soft_deleted_records(): void
    {
        $sq = SupplierQuotation::factory()->create();
        $this->attachSourceDocument($sq, 'same-bytes');
        $sq->delete();

        $matches = app(FindPreviousImportsOfFileAction::class)
            ->execute($this->tempFileWithContent('same-bytes'));

        $this->assertCount(0, $matches);
    }

    public function test_upload_of_already_imported_file_warns_with_reference(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty'], ['P-1', 2]]);
        $tmp = tempnam(sys_get_temp_dir(), 'dup').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);

        $sq = SupplierQuotation::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->attachSourceDocument($sq, $bytes);

        $component = Livewire::test(Assistant::class)
            ->set('upload', UploadedFile::fake()->createWithContent('cotacao.xlsx', $bytes))
            ->call('submitImport');

        $matches = $component->get('importDuplicateMatches');
        $this->assertCount(1, $matches);
        $this->assertSame($sq->reference, $matches[0]['reference']);

        $messages = collect($component->get('messages'))->pluck('text')->implode("\n");
        $this->assertStringContainsString($sq->reference, $messages);
    }

    public function test_upload_of_new_file_produces_no_duplicate_warning(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part'], ['P-9']]);
        $tmp = tempnam(sys_get_temp_dir(), 'dup').'.xlsx';
        (new Xlsx($ss))->save($tmp);

        $component = Livewire::test(Assistant::class)
            ->set('upload', UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp)))
            ->call('submitImport');

        $this->assertSame([], $component->get('importDuplicateMatches'));
    }
}
