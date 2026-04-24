<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\Financial\Reports\Support\FxConverter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FxConverterTest extends TestCase
{
    public function test_same_currency_returns_input_unchanged(): void
    {
        $converter = new FxConverter('USD', []);

        $result = $converter->convertDocument(123_456, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertSame(123_456, $result);
    }

    public function test_uses_cached_rate_on_or_before_date(): void
    {
        $cache = [
            'USD>BRL|2026-03-10' => 5.10,
            'USD>BRL|2026-03-15' => 5.20,
        ];
        $converter = new FxConverter('BRL', $cache);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-14'));

        $this->assertSame(510_0000, $result);
    }

    public function test_uses_latest_rate_when_exact_date_matches(): void
    {
        $cache = [
            'USD>BRL|2026-03-10' => 5.10,
            'USD>BRL|2026-03-15' => 5.20,
        ];
        $converter = new FxConverter('BRL', $cache);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertSame(520_0000, $result);
    }

    public function test_returns_null_when_no_rate_available(): void
    {
        $converter = new FxConverter('BRL', []);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertNull($result);
    }

    public function test_returns_null_when_all_cached_rates_are_after_requested_date(): void
    {
        $cache = ['USD>BRL|2026-03-20' => 5.20];
        $converter = new FxConverter('BRL', $cache);

        $result = $converter->convertDocument(100_0000, 'USD', CarbonImmutable::parse('2026-03-15'));

        $this->assertNull($result);
    }

    public function test_convert_payment_returns_null_when_currency_unknown(): void
    {
        $allocation = new \App\Domain\Financial\Models\PaymentAllocation();
        $allocation->allocated_amount_in_document_currency = 100_0000;
        // No scheduleItem, no payment relation loaded — currency chain returns null

        $converter = new FxConverter('USD', []);

        $this->assertNull($converter->convertPayment($allocation));
    }

    public function test_convert_payment_returns_null_when_payment_date_missing(): void
    {
        $payment = new \App\Domain\Financial\Models\Payment();
        $payment->currency_code = 'BRL';
        // payment_date is null

        $allocation = new \App\Domain\Financial\Models\PaymentAllocation();
        $allocation->allocated_amount_in_document_currency = 100_0000;
        $allocation->setRelation('payment', $payment);

        $converter = new FxConverter('USD', ['BRL>USD|2026-03-15' => 0.18]);

        $this->assertNull($converter->convertPayment($allocation));
    }
}
