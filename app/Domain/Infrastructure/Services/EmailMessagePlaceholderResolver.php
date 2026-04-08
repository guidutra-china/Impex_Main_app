<?php

namespace App\Domain\Infrastructure\Services;

class EmailMessagePlaceholderResolver
{
    /**
     * Substitute {variable} tokens in a template string against a context array.
     *
     * - Null or empty templates return ''.
     * - Unknown placeholders (keys not present in $context) are left literal.
     * - Null or empty context values are substituted as ''.
     * - Double spaces left by empty substitutions are collapsed to a single space.
     * - Leading/trailing whitespace is trimmed.
     *
     * @param array<string, scalar|\Stringable|null> $context
     */
    public function resolve(?string $template, array $context): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $resolved = $template;
        foreach ($context as $key => $value) {
            $resolved = str_replace(
                '{' . $key . '}',
                (string) ($value ?? ''),
                $resolved
            );
        }

        return trim(preg_replace('/ {2,}/', ' ', $resolved));
    }
}
