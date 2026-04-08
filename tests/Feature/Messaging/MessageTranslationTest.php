<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ClaudeTranslationService;
use App\Domain\Messaging\Services\MessageTranslator;
use App\Domain\Messaging\Services\MessagingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageTranslationTest extends TestCase
{
    use RefreshDatabase;

    private MessagingService $messaging;
    private MessageTranslator $translator;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        // Force a Claude API key to be present so isConfigured() returns true.
        config(['services.anthropic.key' => 'test-key']);

        $this->messaging = app(MessagingService::class);
        $this->translator = app(MessageTranslator::class);

        $this->alice = User::factory()->create(['name' => 'Alice', 'locale' => 'pt_BR']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'locale' => 'en']);
    }

    public function test_chinese_text_is_detected_via_heuristic_without_api_call(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([], 500), // any call would fail
        ]);

        $detected = app(ClaudeTranslationService::class)->detect('你好，订单已发货');

        $this->assertSame('zh_CN', $detected);
        Http::assertNothingSent();
    }

    public function test_send_message_populates_detected_locale(): void
    {
        $this->fakeClaudeDetect('en');

        $conv = $this->messaging->createConversation($this->alice, [$this->bob->id]);
        $message = $this->messaging->sendMessage($conv, $this->alice, 'Hello, please confirm the shipment date.');

        $this->assertSame('en', $message->detected_locale);
    }

    public function test_translate_for_locale_returns_original_when_locales_match(): void
    {
        Http::preventStrayRequests();

        $message = $this->makeMessage('Hello world', detectedLocale: 'en');

        $result = $this->translator->translateForLocale($message, 'en');

        $this->assertSame('Hello world', $result);
    }

    public function test_translate_for_locale_calls_claude_and_caches_result(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => 'Olá, por favor confirme a data do embarque.']],
            ], 200),
        ]);

        $message = $this->makeMessage('Hello, please confirm the shipment date.', detectedLocale: 'en');

        $first = $this->translator->translateForLocale($message, 'pt_BR');
        $this->assertSame('Olá, por favor confirme a data do embarque.', $first);
        $this->assertDatabaseHas('message_translations', [
            'message_id' => $message->id,
            'locale'     => 'pt_BR',
            'body'       => 'Olá, por favor confirme a data do embarque.',
            'provider'   => 'claude',
        ]);
        Http::assertSentCount(1);

        // Second call hits cache, no second HTTP request.
        $second = $this->translator->translateForLocale($message->fresh(), 'pt_BR');
        $this->assertSame('Olá, por favor confirme a data do embarque.', $second);
        Http::assertSentCount(1);
    }

    public function test_translate_for_locale_returns_null_when_claude_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response('boom', 500),
        ]);

        $message = $this->makeMessage('Hello', detectedLocale: 'en');

        $result = $this->translator->translateForLocale($message, 'pt_BR');

        $this->assertNull($result);
        $this->assertDatabaseCount('message_translations', 0);
    }

    public function test_translate_skips_unsupported_target_locale(): void
    {
        Http::preventStrayRequests();

        $message = $this->makeMessage('Hello', detectedLocale: 'en');

        $result = $this->translator->translateForLocale($message, 'fr_FR');

        $this->assertNull($result);
    }

    private function makeMessage(string $body, ?string $detectedLocale): Message
    {
        $conv = Conversation::create([
            'created_by' => $this->alice->id,
            'type'       => 'direct',
        ]);

        return Message::create([
            'conversation_id' => $conv->id,
            'sender_id'       => $this->alice->id,
            'body'            => $body,
            'detected_locale' => $detectedLocale,
        ]);
    }

    private function fakeClaudeDetect(string $locale): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => $locale]],
            ], 200),
        ]);
    }
}
