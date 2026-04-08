<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageTranslation;

/**
 * Cache-aware façade over ClaudeTranslationService. Translations are stored
 * append-only in `message_translations` keyed by (message_id, locale) so
 * Claude is only called the first time anyone in that locale clicks Translate.
 */
class MessageTranslator
{
    public function __construct(
        private readonly ClaudeTranslationService $claude,
    ) {
    }

    /**
     * Detect the source language of a message body. Best-effort — may return
     * null if Claude is unconfigured or detection fails.
     */
    public function detect(string $body): ?string
    {
        return $this->claude->detect($body);
    }

    /**
     * Get a translation of $message into $targetLocale. If a cached row exists,
     * returns it directly. Otherwise calls Claude, stores the result, and
     * returns the new translation. Returns null only if Claude both has no
     * cached row AND fails to translate.
     */
    public function translateForLocale(Message $message, string $targetLocale): ?string
    {
        // Same language as detected source → return original, no need to translate.
        if ($message->detected_locale === $targetLocale) {
            return $message->body;
        }

        $cached = $message->translations()
            ->where('locale', $targetLocale)
            ->first();

        if ($cached) {
            return $cached->body;
        }

        $translated = $this->claude->translate(
            text: $message->body,
            targetLocale: $targetLocale,
            sourceLocale: $message->detected_locale,
        );

        if ($translated === null || $translated === '') {
            return null;
        }

        MessageTranslation::create([
            'message_id' => $message->id,
            'locale'     => $targetLocale,
            'body'       => $translated,
            'provider'   => 'claude',
            'created_at' => now(),
        ]);

        return $translated;
    }
}
