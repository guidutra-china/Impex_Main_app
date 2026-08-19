<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Filament\Pages\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Concurrent import sessions must not share temp storage: cancelling (or confirming)
 * one session may only delete that session's uploaded file and extracted images,
 * never another user's in-flight image pool.
 */
class ImportSessionIsolationTest extends TestCase
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

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 50.0, 'source_row' => 2]],
                ];
            }
        });
    }

    public function test_cancelling_one_session_keeps_another_sessions_extracted_images(): void
    {
        $this->actingAsImporter();

        // Session A uploads and extracts an embedded image.
        $sessionA = Livewire::test(Assistant::class)
            ->set('upload', $this->xlsxWithImage())
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation');

        $poolA = $sessionA->get('importImagePool');
        $this->assertNotEmpty($poolA, 'Session A should have extracted an image');
        $this->assertFileExists($poolA[0]['path']);

        // Session B uploads the same kind of file, then cancels.
        Livewire::test(Assistant::class)
            ->set('upload', $this->xlsxWithImage())
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->call('cancelImport');

        // Session A's extracted image must survive session B's cleanup.
        $this->assertFileExists($poolA[0]['path']);
    }

    public function test_sessions_store_uploads_in_separate_directories(): void
    {
        $this->actingAsImporter();

        $pathA = Livewire::test(Assistant::class)
            ->set('upload', $this->xlsxWithImage())
            ->call('submitImport')
            ->get('importFilePath');

        $pathB = Livewire::test(Assistant::class)
            ->set('upload', $this->xlsxWithImage())
            ->call('submitImport')
            ->get('importFilePath');

        $this->assertNotSame(dirname($pathA), dirname($pathB));
    }

    public function test_cancel_removes_own_session_directory(): void
    {
        $this->actingAsImporter();

        $session = Livewire::test(Assistant::class)
            ->set('upload', $this->xlsxWithImage())
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation');

        $filePath = $session->get('importFilePath');
        $sessionDir = dirname($filePath);
        $this->assertDirectoryExists($sessionDir);

        $session->call('cancelImport');

        $this->assertFileDoesNotExist($filePath);
        $this->assertDirectoryDoesNotExist($sessionDir);
    }

    private function actingAsImporter(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        return $user;
    }

    /** A real xlsx containing one embedded image anchored below the header row. */
    private function xlsxWithImage(): UploadedFile
    {
        // 1x1 PNG (valid image bytes for the drawing).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $pngPath = tempnam(sys_get_temp_dir(), 'img').'.png';
        file_put_contents($pngPath, $png);

        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);

        $drawing = new Drawing;
        $drawing->setPath($pngPath);
        $drawing->setCoordinates('A2');
        $drawing->setWorksheet($sheet);

        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);

        return UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));
    }
}
