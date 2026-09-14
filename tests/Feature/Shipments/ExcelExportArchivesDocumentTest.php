<?php

namespace Tests\Feature\Shipments;

use App\Domain\Infrastructure\Models\DocumentVersion;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Commercial Invoice e Packing List em Excel deixam de ser só download: cada
 * exportação entra na aba Documents com versionamento, em tipo próprio
 * (…_xlsx), sem misturar com a linha do tempo do PDF.
 */
class ExcelExportArchivesDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');

        $this->shipment = Shipment::factory()->create();
    }

    public function test_commercial_invoice_excel_is_archived_with_a_version_and_still_downloaded(): void
    {
        Livewire::test(ViewShipment::class, ['record' => $this->shipment->getKey()])
            ->callAction('exportCommercialInvoiceExcel')
            ->assertFileDownloaded();

        $document = $this->shipment->fresh()->getLatestDocument('commercial_invoice_xlsx');

        $this->assertNotNull($document);
        $this->assertSame(1, $document->version);
        $this->assertSame('CI-'.$this->shipment->reference.'-v1.xlsx', $document->name);
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertStringContainsString('spreadsheet', (string) $document->mime_type);

        // O PDF não é tocado: linhas do tempo separadas.
        $this->assertNull($this->shipment->fresh()->getLatestDocument('commercial_invoice_pdf'));
    }

    public function test_exporting_again_bumps_the_version_and_keeps_the_previous_file_in_history(): void
    {
        $page = Livewire::test(ViewShipment::class, ['record' => $this->shipment->getKey()]);
        $page->callAction('exportCommercialInvoiceExcel');
        $first = $this->shipment->fresh()->getLatestDocument('commercial_invoice_xlsx');

        $page->callAction('exportCommercialInvoiceExcel');
        $second = $this->shipment->fresh()->getLatestDocument('commercial_invoice_xlsx');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->version);
        $this->assertSame('CI-'.$this->shipment->reference.'-v2.xlsx', $second->name);

        $history = DocumentVersion::where('document_id', $first->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(1, $history->first()->version);
        $this->assertTrue(Storage::disk('local')->exists($history->first()->path));
    }

    public function test_packing_list_excel_is_archived_under_its_own_type(): void
    {
        Livewire::test(ViewShipment::class, ['record' => $this->shipment->getKey()])
            ->callAction('exportPackingListExcel');

        $document = $this->shipment->fresh()->getLatestDocument('packing_list_xlsx');

        $this->assertNotNull($document);
        $this->assertSame('PL-'.$this->shipment->reference.'-v1.xlsx', $document->name);
        $this->assertNull($this->shipment->fresh()->getLatestDocument('commercial_invoice_xlsx'));
    }
}
