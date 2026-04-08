<?php

namespace Tests\Feature\Messaging;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MessagingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private MessagingService $service;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        config(['services.anthropic.key' => '']);

        Storage::fake('local');

        $this->service = app(MessagingService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    public function test_send_message_persists_attachment_as_document(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $file = File::create('contract.pdf', 10, 'application/pdf');

        $message = $this->service->sendMessage(
            conversation: $conv,
            sender: $this->alice,
            body: 'Please review',
            attachments: [$file],
        );

        $this->assertCount(1, $message->documents);
        /** @var Document $doc */
        $doc = $message->documents->first();
        $this->assertSame('contract.pdf', $doc->name);
        $this->assertSame(Message::ATTACHMENT_TYPE, $doc->type);
        $this->assertSame(Message::class, $doc->documentable_type);
        $this->assertSame($message->id, $doc->documentable_id);
        $this->assertSame($this->alice->id, $doc->created_by);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_send_message_allows_empty_body_when_attachment_is_present(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $message = $this->service->sendMessage(
            conversation: $conv,
            sender: $this->alice,
            body: '',
            attachments: [File::create('spec.pdf', 5, 'application/pdf')],
        );

        $this->assertSame('', $message->body);
        $this->assertCount(1, $message->documents);
    }

    public function test_send_message_rejects_empty_body_and_no_attachments(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->sendMessage($conv, $this->alice, '', []);
    }

    public function test_preview_route_streams_file_to_participant(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $message = $this->service->sendMessage(
            $conv, $this->alice, 'Doc attached',
            [File::create('po.pdf', 10, 'application/pdf')],
        );
        $doc = $message->documents->first();

        $url = URL::temporarySignedRoute(
            'messaging.documents.preview',
            now()->addMinutes(5),
            ['document' => $doc->id],
        );

        $response = $this->actingAs($this->bob)->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_preview_route_blocks_non_participant_even_with_valid_signature(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $message = $this->service->sendMessage(
            $conv, $this->alice, 'Doc attached',
            [File::create('secret.pdf', 10, 'application/pdf')],
        );
        $doc = $message->documents->first();

        $url = URL::temporarySignedRoute(
            'messaging.documents.preview',
            now()->addMinutes(5),
            ['document' => $doc->id],
        );

        $intruder = User::factory()->create();
        $response = $this->actingAs($intruder)->get($url);
        $response->assertForbidden();
    }

    public function test_preview_route_rejects_invalid_signature(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $message = $this->service->sendMessage(
            $conv, $this->alice, 'Doc',
            [File::create('x.pdf', 5, 'application/pdf')],
        );
        $doc = $message->documents->first();

        // Route without signature — Laravel's signed middleware must reject it.
        $response = $this->actingAs($this->bob)->get(
            route('messaging.documents.preview', ['document' => $doc->id])
        );
        $response->assertForbidden();
    }
}
