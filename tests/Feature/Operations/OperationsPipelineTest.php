<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Operations\OperationsPipeline;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_declares_six_stages_in_order(): void
    {
        $keys = array_map(fn ($s) => $s->key, OperationsPipeline::stages());

        $this->assertSame(
            ['inquiry', 'quoting', 'pi_issued', 'in_production', 'shipping', 'delivered'],
            $keys,
        );
    }

    public function test_inquiry_stage_carries_model_and_statuses(): void
    {
        $stage = OperationsPipeline::stage('inquiry');

        $this->assertSame(Inquiry::class, $stage->modelClass);
        $this->assertEqualsCanonicalizing(
            [InquiryStatus::RECEIVED, InquiryStatus::QUOTING],
            $stage->statuses,
        );
        $this->assertSame('Inquiry', $stage->title);
        $this->assertSame('gray', $stage->color);
    }

    public function test_pi_issued_stage_query_filters_by_its_statuses(): void
    {
        ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::DRAFT->value]);
        ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::CANCELLED->value]);

        $stage = OperationsPipeline::stage('pi_issued');
        $refs = $stage->query()->pluck('status')->map(fn ($s) => $s->value)->all();

        $this->assertContains(ProformaInvoiceStatus::DRAFT->value, $refs);
        $this->assertNotContains(ProformaInvoiceStatus::CANCELLED->value, $refs);
    }

    public function test_stage_throws_for_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OperationsPipeline::stage('nope');
    }
}
