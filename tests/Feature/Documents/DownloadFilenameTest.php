<?php

namespace Tests\Feature\Documents;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Models\DocumentVersion;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 'PL-SH-2026-00027-v1.xlsx.xlsx' no download: a aba colava a extensão ao
 * nome sem olhar se ele já a tinha (o .pdf duplicava do mesmo jeito). Nome
 * de download passa a vir de um lugar só, no modelo.
 */
class DownloadFilenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_documents_keep_a_single_extension_and_uploads_gain_one(): void
    {
        $generatedXlsx = new Document(['name' => 'PL-SH-2026-00027-v1.xlsx', 'path' => 'documents/x/pl_20260915.xlsx']);
        $generatedPdf = new Document(['name' => 'CI-SH-2026-00051-v1.pdf', 'path' => 'documents/x/ci_20260910.pdf']);
        $uploaded = new Document(['name' => 'Signed Contract', 'path' => 'documents/x/abc123.pdf']);
        $caseMix = new Document(['name' => 'Report.PDF', 'path' => 'documents/x/abc.pdf']);

        $this->assertSame('PL-SH-2026-00027-v1.xlsx', $generatedXlsx->downloadFilename());
        $this->assertSame('CI-SH-2026-00051-v1.pdf', $generatedPdf->downloadFilename());
        $this->assertSame('Signed Contract.pdf', $uploaded->downloadFilename());
        $this->assertSame('Report.PDF', $caseMix->downloadFilename());
    }

    public function test_old_version_download_swaps_the_version_number_instead_of_stacking_it(): void
    {
        $document = new Document(['name' => 'CI-SH-2026-00051-v2.pdf', 'path' => 'documents/x/v2.pdf']);
        $version = new DocumentVersion(['path' => 'documents/x/v1.pdf', 'version' => 1]);

        $this->assertSame('CI-SH-2026-00051-v1.pdf', $document->versionDownloadFilename($version));

        $uploaded = new Document(['name' => 'Signed Contract', 'path' => 'documents/x/b.pdf']);
        $this->assertSame('Signed Contract-v1.pdf', $uploaded->versionDownloadFilename($version));
    }

    public function test_documents_tab_and_version_history_download_with_clean_names(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');

        $shipment = Shipment::factory()->create(['reference' => 'SH-2026-00027']);
        $service = new DocumentService;
        $service->storeGenerated($shipment, 'x', 'packing_list_xlsx', 'PL-SH-2026-00027-v1.xlsx', 'xlsx');
        $document = $service->storeGenerated($shipment, 'y', 'packing_list_xlsx', 'PL-SH-2026-00027-v2.xlsx', 'xlsx');

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $shipment->fresh(),
            'pageClass' => ViewShipment::class,
        ])
            ->callTableAction('download', $document)
            ->assertFileDownloaded('PL-SH-2026-00027-v2.xlsx');

        $history = DocumentVersion::where('document_id', $document->id)->firstOrFail();

        $this->get(URL::signedRoute('document-version.download', ['version' => $history->id]))
            ->assertOk()
            ->assertDownload('PL-SH-2026-00027-v1.xlsx');
    }
}
