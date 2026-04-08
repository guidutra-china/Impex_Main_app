<?php

namespace Tests\Feature\Filament\Actions;

use App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver;
use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendDocumentByEmailActionDefaultMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_template_is_loadable_for_all_seven_keys(): void
    {
        $settings = app(CompanySettings::class);

        $this->assertNotNull($settings->email_default_message_quotation);
        $this->assertNotNull($settings->email_default_message_purchase_order);
        $this->assertNotNull($settings->email_default_message_proforma_invoice);
        $this->assertNotNull($settings->email_default_message_rfq);
        $this->assertNotNull($settings->email_default_message_packing_list);
        $this->assertNotNull($settings->email_default_message_commercial_invoice);
        $this->assertNotNull($settings->email_default_message_fair_inquiry);
    }

    public function test_purchase_order_template_resolves_placeholders_against_a_real_context(): void
    {
        $settings = app(CompanySettings::class);
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $resolved = $resolver->resolve(
            $settings->email_default_message_purchase_order,
            [
                'recipient_name' => 'John Silva',
                'company_name'   => 'Acme Corp',
                'reference'      => 'PO-2026-0042',
                'document_name'  => 'PO-2026-0042.pdf',
            ]
        );

        $this->assertStringContainsString('John Silva', $resolved);
        $this->assertStringContainsString('PO-2026-0042', $resolved);
        $this->assertStringNotContainsString('{recipient_name}', $resolved);
        $this->assertStringNotContainsString('{reference}', $resolved);
    }

    public function test_fair_inquiry_template_resolves_fair_specific_placeholders(): void
    {
        $settings = app(CompanySettings::class);
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $resolved = $resolver->resolve(
            $settings->email_default_message_fair_inquiry,
            [
                'recipient_name'  => 'Ms. Wang',
                'company_name'    => 'Foshan Lighting',
                'trade_fair_name' => 'Canton Fair Phase 2',
                'product_names'   => 'LED Bulbs, Pendant Lamps',
            ]
        );

        $this->assertStringContainsString('Ms. Wang', $resolved);
        $this->assertStringContainsString('Canton Fair Phase 2', $resolved);
        $this->assertStringContainsString('LED Bulbs, Pendant Lamps', $resolved);
        $this->assertStringNotContainsString('{trade_fair_name}', $resolved);
        $this->assertStringNotContainsString('{product_names}', $resolved);
    }

    public function test_null_template_returns_empty_string_without_error(): void
    {
        $resolver = app(EmailMessagePlaceholderResolver::class);

        $this->assertSame('', $resolver->resolve(null, ['recipient_name' => 'John']));
    }
}
