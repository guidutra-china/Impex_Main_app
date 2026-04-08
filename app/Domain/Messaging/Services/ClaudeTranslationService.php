<?php

namespace App\Domain\Messaging\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Claude Messages API for two narrow tasks:
 *   1. Detecting the language of a chat message
 *   2. Translating a chat message to a target locale
 *
 * Mirrors the configuration & error-handling style of ClaudeAnalysisService
 * (best-effort, never throws — returns nulls so the caller decides UX).
 */
class ClaudeTranslationService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    /**
     * Locales the messaging system supports — must match SetLocale middleware.
     */
    public const SUPPORTED_LOCALES = ['en', 'pt_BR', 'zh_CN'];

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Detect the language of a single short text. Returns one of the supported
     * locale codes ("en", "pt_BR", "zh_CN") or null on failure / unknown.
     */
    public function detect(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || ! $this->isConfigured()) {
            return null;
        }

        // Cheap heuristic short-circuit before spending an API call.
        $heuristic = $this->heuristicDetect($text);
        if ($heuristic !== null) {
            return $heuristic;
        }

        $prompt = <<<PROMPT
You are a language detector. Identify the language of the text below.

Reply with ONLY ONE token, no punctuation, no explanation:
- "en" for English
- "pt_BR" for Portuguese (Brazil or Portugal)
- "zh_CN" for any variant of Chinese
- "unknown" for anything else

TEXT:
{$text}
PROMPT;

        $reply = $this->call($prompt, maxTokens: 8);
        if ($reply === null) {
            return null;
        }

        $normalized = strtolower(trim($reply));
        $normalized = preg_replace('/[^a-z_]/', '', $normalized) ?? '';

        return in_array($normalized, self::SUPPORTED_LOCALES, true)
            ? $normalized
            : null;
    }

    /**
     * Translate a chat message to the target locale. Returns the translated
     * string or null on failure (caller falls back to original).
     */
    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): ?string
    {
        $text = trim($text);
        if ($text === '' || ! $this->isConfigured()) {
            return null;
        }

        if (! in_array($targetLocale, self::SUPPORTED_LOCALES, true)) {
            return null;
        }

        $targetName = match ($targetLocale) {
            'en'    => 'English',
            'pt_BR' => 'Brazilian Portuguese',
            'zh_CN' => 'Simplified Chinese',
        };

        $sourceClause = $sourceLocale ? "from {$this->localeName($sourceLocale)} " : '';

        $prompt = <<<PROMPT
You are a professional translator for an import/export trading company.
Translate the chat message below {$sourceClause}into {$targetName}.

Rules:
- Preserve technical terms, product codes, document references like "PI-2026-00001",
  numbers, units, and proper nouns exactly as written.
- Keep the tone informal and conversational, matching how trading partners chat.
- Reply with ONLY the translated text. No quotes, no preamble, no explanation.

MESSAGE:
{$text}
PROMPT;

        return $this->call($prompt, maxTokens: 1024);
    }

    /**
     * Single Claude API call. Returns the assistant text on success, null on
     * any failure (logged, never thrown).
     */
    private function call(string $prompt, int $maxTokens): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
                ->timeout(15)
                ->post($this->baseUrl, [
                    'model'      => $this->model,
                    'max_tokens' => $maxTokens,
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Claude translation HTTP error: ' . $response->status() . ' - ' . $response->body());

                return null;
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? null;

            return is_string($text) ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('Claude translation exception: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Cheap pre-flight: if the text uses CJK characters we can be confident
     * it's Chinese without paying for an API call. Latin scripts can't be
     * disambiguated this way (en vs pt_BR), so we delegate to Claude.
     */
    private function heuristicDetect(string $text): ?string
    {
        if (preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]/u', $text)) {
            return 'zh_CN';
        }

        return null;
    }

    private function localeName(string $locale): string
    {
        return match ($locale) {
            'en'    => 'English',
            'pt_BR' => 'Brazilian Portuguese',
            'zh_CN' => 'Simplified Chinese',
            default => $locale,
        };
    }
}
