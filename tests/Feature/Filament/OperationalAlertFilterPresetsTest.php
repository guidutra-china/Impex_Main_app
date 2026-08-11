<?php

namespace Tests\Feature\Filament;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Operational Dashboard "Action Required" alerts deep-link to list pages
 * with preset filters. These filters must reproduce exactly the condition the
 * alert counted, so the list shows the same records as the alert number.
 */
class OperationalAlertFilterPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    public function test_pi_without_po_filter_shows_only_finalized_pis_without_po(): void
    {
        $finalizedWithoutPo = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::FINALIZED->value]);

        $finalizedWithPo = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::FINALIZED->value]);
        PurchaseOrder::factory()->create(['proforma_invoice_id' => $finalizedWithPo->id]);

        $draftWithoutPo = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::DRAFT->value]);

        Livewire::test(ListProformaInvoices::class)
            ->filterTable('status', ProformaInvoiceStatus::FINALIZED->value)
            ->filterTable('without_po', true)
            ->assertCanSeeTableRecords([$finalizedWithoutPo])
            ->assertCanNotSeeTableRecords([$finalizedWithPo, $draftWithoutPo]);
    }

    public function test_po_stalled_filter_shows_only_active_pos_without_recent_updates(): void
    {
        $stalled = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::CONFIRMED->value]);
        DB::table('purchase_orders')->where('id', $stalled->id)
            ->update(['updated_at' => now()->subDays(20)]);

        $recentlyUpdated = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::CONFIRMED->value]);

        $oldDraft = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::DRAFT->value]);
        DB::table('purchase_orders')->where('id', $oldDraft->id)
            ->update(['updated_at' => now()->subDays(20)]);

        Livewire::test(ListPurchaseOrders::class)
            ->filterTable('stalled', true)
            ->assertCanSeeTableRecords([$stalled])
            ->assertCanNotSeeTableRecords([$recentlyUpdated, $oldDraft]);
    }

    public function test_inquiry_open_aging_filter_shows_only_old_open_inquiries(): void
    {
        $oldOpen = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);
        DB::table('inquiries')->where('id', $oldOpen->id)
            ->update(['created_at' => now()->subDays(10)]);

        $freshOpen = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);

        $oldQuoted = Inquiry::factory()->create(['status' => InquiryStatus::QUOTED->value]);
        DB::table('inquiries')->where('id', $oldQuoted->id)
            ->update(['created_at' => now()->subDays(10)]);

        Livewire::test(ListInquiries::class)
            ->filterTable('open_aging', true)
            ->assertCanSeeTableRecords([$oldOpen])
            ->assertCanNotSeeTableRecords([$freshOpen, $oldQuoted]);
    }

    public function test_alert_urls_carry_the_filter_presets(): void
    {
        ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::FINALIZED->value]);

        $stalled = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::CONFIRMED->value]);
        DB::table('purchase_orders')->where('id', $stalled->id)
            ->update(['updated_at' => now()->subDays(20)]);

        $oldOpen = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);
        DB::table('inquiries')->where('id', $oldOpen->id)
            ->update(['created_at' => now()->subDays(10)]);

        Livewire::test(\App\Filament\Widgets\OperationalAlertsWidget::class)
            ->assertSeeHtml('without_po')
            ->assertSeeHtml('stalled')
            ->assertSeeHtml('open_aging');
    }
}
