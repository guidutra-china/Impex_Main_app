<?php

namespace Tests\Feature\Shipments;

use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PreviewPdfGeneratesDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');
    }

    public function test_submitting_the_packing_list_preview_generates_the_document(): void
    {
        $shipment = Shipment::factory()->create();

        $this->assertNull($shipment->getLatestDocument('packing_list_pdf'));

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->callAction('previewPackingListPdf');

        $document = $shipment->fresh()->getLatestDocument('packing_list_pdf');
        $this->assertNotNull($document, 'submitting the preview modal should generate the PDF document');
        $this->assertStringContainsString('PL-'.$shipment->reference, $document->name);
    }
}
