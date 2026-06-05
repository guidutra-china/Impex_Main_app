<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Pages\OrderPipelineKanban;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPipelineKanbanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament::getUrl() calls route() which needs the panel to be set
        // so it can resolve route names like filament.admin.resources.inquiries.view
        Filament::setCurrentPanel('admin');
    }

    public function test_columns_are_built_from_the_pipeline_and_records_land_in_the_right_stage(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::DRAFT->value]);

        $columns = (new OrderPipelineKanban)->getColumns();

        $this->assertSame(
            ['inquiry', 'quoting', 'pi_issued', 'in_production', 'shipping', 'delivered'],
            array_map(fn ($c) => $c['id'], $columns),
        );

        $inquiryCol = collect($columns)->firstWhere('id', 'inquiry');
        $piCol = collect($columns)->firstWhere('id', 'pi_issued');

        $this->assertContains($inquiry->id, array_column($inquiryCol['cards'], 'id'));
        $this->assertContains($pi->id, array_column($piCol['cards'], 'id'));
    }
}
