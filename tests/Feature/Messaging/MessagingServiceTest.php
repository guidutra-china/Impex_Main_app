<?php

namespace Tests\Feature\Messaging;

use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageReference;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Services\ReferenceResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessagingService $service;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests focus on conversation/message logic — Claude detection
        // is exercised separately. Block any stray HTTP so a misconfigured
        // env can't cause flaky network timeouts here.
        Http::preventStrayRequests();
        Http::fake();
        config(['services.anthropic.key' => '']);

        $this->service = app(MessagingService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    public function test_create_conversation_adds_creator_and_participants(): void
    {
        $conv = $this->service->createConversation(
            creator: $this->alice,
            participantUserIds: [$this->bob->id],
            subject: 'Test',
        );

        $this->assertSame('Test', $conv->subject);
        $this->assertSame($this->alice->id, $conv->created_by);
        $this->assertCount(2, $conv->participants);
        $this->assertTrue($conv->hasParticipant($this->alice));
        $this->assertTrue($conv->hasParticipant($this->bob));
    }

    public function test_send_message_persists_body_and_updates_last_message_at(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $message = $this->service->sendMessage($conv, $this->alice, 'Hello Bob');

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Hello Bob', $message->body);
        $this->assertSame($this->alice->id, $message->sender_id);
        $this->assertNotNull($conv->fresh()->last_message_at);
    }

    public function test_send_message_resolves_inquiry_reference_and_creates_message_reference_row(): void
    {
        $inquiry = Inquiry::factory()->create(['reference' => 'INQ-2026-00007']);

        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $message = $this->service->sendMessage($conv, $this->alice, 'Please check @INQ-2026-00007 today');

        $this->assertCount(1, $message->references);
        /** @var MessageReference $ref */
        $ref = $message->references->first();
        $this->assertSame('INQ-2026-00007', $ref->reference_code);
        $this->assertSame('INQ', $ref->document_type);
        $this->assertSame($inquiry->id, $ref->referenceable_id);
        $this->assertSame(Inquiry::class, $ref->referenceable_type);
    }

    public function test_send_message_skips_unknown_or_nonexistent_references(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $message = $this->service->sendMessage(
            $conv,
            $this->alice,
            'Unknown @ZZ-2026-00001 and missing @INQ-2026-99999'
        );

        $this->assertCount(0, $message->references);
    }

    public function test_send_message_rejects_non_participant(): void
    {
        $intruder = User::factory()->create();
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $this->expectException(\DomainException::class);
        $this->service->sendMessage($conv, $intruder, 'I should not be here');
    }

    public function test_send_message_rejects_empty_body(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->sendMessage($conv, $this->alice, '   ');
    }

    public function test_mark_as_read_updates_participants_last_read_at(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $this->service->sendMessage($conv, $this->alice, 'Hi');

        $this->service->markAsRead($conv, $this->bob);

        $bobParticipant = $conv->participants()->where('user_id', $this->bob->id)->first();
        $this->assertNotNull($bobParticipant->last_read_at);
    }

    public function test_conversation_policy_blocks_non_participant(): void
    {
        $outsider = User::factory()->create();
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        $this->assertTrue($outsider->cannot('view', $conv));
        $this->assertTrue($this->bob->can('view', $conv));
    }

    public function test_create_conversation_with_subject_entity_persists_morph(): void
    {
        $inquiry = Inquiry::factory()->create(['reference' => 'INQ-2026-00042']);

        $conv = $this->service->createConversation(
            creator: $this->alice,
            participantUserIds: [$this->bob->id],
            subject: 'Discuss INQ-2026-00042',
            type: 'entity_thread',
            subjectEntity: $inquiry,
        );

        $this->assertSame('entity_thread', $conv->type);
        $this->assertSame($inquiry->id, $conv->subject_entity_id);
        $this->assertSame(Inquiry::class, $conv->subject_entity_type);

        $conv->load('subjectEntity');
        $this->assertNotNull($conv->subjectEntity);
        $this->assertSame('INQ-2026-00042', $conv->subjectEntity->reference);
    }

    public function test_unread_messages_count_reflects_inbox_state(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);

        // Bob has nothing unread initially.
        $this->assertSame(0, $this->bob->fresh()->unreadMessagesCount());

        // Alice sends two messages — Bob now has 2 unread.
        $this->service->sendMessage($conv, $this->alice, 'first');
        $this->service->sendMessage($conv, $this->alice, 'second');
        $this->assertSame(2, $this->bob->fresh()->unreadMessagesCount());

        // Alice's own messages don't count for her.
        $this->assertSame(0, $this->alice->fresh()->unreadMessagesCount());

        // After marking as read, count goes back to 0 for Bob.
        $this->service->markAsRead($conv, $this->bob);
        $this->assertSame(0, $this->bob->fresh()->unreadMessagesCount());
    }

    public function test_send_message_writes_database_notification_to_other_participants(): void
    {
        $conv = $this->service->createConversation($this->alice, [$this->bob->id]);
        $this->service->sendMessage($conv, $this->alice, 'Hi Bob');

        // Filament/Laravel database notification was written for Bob, not Alice.
        $this->assertSame(1, $this->bob->fresh()->notifications()->count());
        $this->assertSame(0, $this->alice->fresh()->notifications()->count());
    }

    public function test_reference_resolver_extracts_unique_tokens(): void
    {
        /** @var ReferenceResolver $resolver */
        $resolver = app(ReferenceResolver::class);

        $tokens = $resolver->extractTokens(
            'See @PI-2026-00001, also @PI-2026-00001 again, and @PO-2026-00099'
        );

        $this->assertCount(2, $tokens);
        $this->assertSame('PI-2026-00001', $tokens[0]['code']);
        $this->assertSame('PO-2026-00099', $tokens[1]['code']);
    }
}
