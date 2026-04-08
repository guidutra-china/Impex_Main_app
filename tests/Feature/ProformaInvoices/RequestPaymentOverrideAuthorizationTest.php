<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Financial\Actions\RequestPaymentOverrideAuthorizationAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequestPaymentOverrideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private RequestPaymentOverrideAuthorizationAction $action;
    private ProformaInvoice $pi;
    private User $requester;
    private User $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid Anthropic calls inside MessagingService.
        Http::preventStrayRequests();
        Http::fake();
        config(['services.anthropic.key' => '']);

        Permission::firstOrCreate(['name' => 'override-payment-block', 'guard_name' => 'web']);

        $this->action = app(RequestPaymentOverrideAuthorizationAction::class);
        $this->requester = User::factory()->create(['name' => 'Requester']);
        $this->authorizer = User::factory()->create(['name' => 'Boss']);
        $this->authorizer->givePermissionTo('override-payment-block');

        $this->pi = ProformaInvoice::factory()->create(['reference' => 'PI-2026-00123']);

        PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ]);
    }

    public function test_creates_conversation_with_pi_as_subject_entity(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Client confirmed wire, please authorize so I can move the supplier.',
        );

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertSame(ProformaInvoice::class, $conversation->subject_entity_type);
        $this->assertSame($this->pi->id, $conversation->subject_entity_id);
        $this->assertStringContainsString('PI-2026-00123', $conversation->subject);
    }

    public function test_creates_message_containing_justification_and_blockers(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Client confirmed wire today.',
        );

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Client confirmed wire today.', $message->body);
        $this->assertStringContainsString('30% Deposit', $message->body);
        $this->assertStringContainsString('@PI-2026-00123', $message->body);
    }

    public function test_adds_authorizer_as_participant(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Please authorize.',
        );

        $this->assertTrue($conversation->fresh()->hasParticipant($this->authorizer));
        $this->assertTrue($conversation->fresh()->hasParticipant($this->requester));
    }

    public function test_only_users_with_override_permission_appear_in_authorizer_list(): void
    {
        $unrelated = User::factory()->create(['name' => 'Unrelated']);

        $eligible = User::permission('override-payment-block')->pluck('id')->all();

        $this->assertContains($this->authorizer->id, $eligible);
        $this->assertNotContains($unrelated->id, $eligible);
        $this->assertNotContains($this->requester->id, $eligible);
    }
}
