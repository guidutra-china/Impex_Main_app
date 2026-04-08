<?php

namespace Tests\Unit\Domain\Infrastructure\Services;

use App\Domain\Infrastructure\Services\EmailMessagePlaceholderResolver;
use PHPUnit\Framework\TestCase;

class EmailMessagePlaceholderResolverTest extends TestCase
{
    private EmailMessagePlaceholderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EmailMessagePlaceholderResolver();
    }

    public function test_returns_empty_string_when_template_is_null(): void
    {
        $this->assertSame('', $this->resolver->resolve(null, ['recipient_name' => 'John']));
    }

    public function test_returns_empty_string_when_template_is_empty(): void
    {
        $this->assertSame('', $this->resolver->resolve('', ['recipient_name' => 'John']));
    }

    public function test_substitutes_single_placeholder(): void
    {
        $result = $this->resolver->resolve(
            'Dear {recipient_name}, hello.',
            ['recipient_name' => 'John Silva']
        );

        $this->assertSame('Dear John Silva, hello.', $result);
    }

    public function test_substitutes_multiple_placeholders(): void
    {
        $result = $this->resolver->resolve(
            'Dear {recipient_name}, please find attached {reference} for {company_name}.',
            [
                'recipient_name' => 'John',
                'reference'      => 'PO-2026-0042',
                'company_name'   => 'Acme Corp',
            ]
        );

        $this->assertSame('Dear John, please find attached PO-2026-0042 for Acme Corp.', $result);
    }

    public function test_leaves_unknown_placeholder_literal(): void
    {
        $result = $this->resolver->resolve(
            'Hello {recipient_name}, your order {order_id} is ready.',
            ['recipient_name' => 'John']
        );

        $this->assertSame('Hello John, your order {order_id} is ready.', $result);
    }

    public function test_substitutes_null_context_value_as_empty_string(): void
    {
        $result = $this->resolver->resolve(
            'Ref: {reference}',
            ['reference' => null]
        );

        $this->assertSame('Ref:', $result);
    }

    public function test_collapses_double_spaces_from_empty_substitution(): void
    {
        $result = $this->resolver->resolve(
            'Please find {reference} from {company_name}.',
            ['reference' => '', 'company_name' => 'Acme']
        );

        $this->assertSame('Please find from Acme.', $result);
    }

    public function test_casts_non_string_context_values(): void
    {
        $result = $this->resolver->resolve(
            'Order #{order_id}',
            ['order_id' => 42]
        );

        $this->assertSame('Order #42', $result);
    }

    public function test_trims_leading_and_trailing_whitespace(): void
    {
        $result = $this->resolver->resolve(
            '  {recipient_name}  ',
            ['recipient_name' => 'John']
        );

        $this->assertSame('John', $result);
    }
}
