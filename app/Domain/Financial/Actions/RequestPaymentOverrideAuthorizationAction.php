<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;

class RequestPaymentOverrideAuthorizationAction
{
    public function __construct(
        private readonly MessagingService $messaging,
    ) {
    }

    /**
     * Open a conversation between the requester and the chosen authorizers,
     * post the first message containing the justification and a snapshot of
     * the current PO blockers, and return the conversation. The first message
     * references the PI via @PI-XXXX so the existing ReferenceResolver renders
     * it as a clickable chip in the chat UI.
     *
     * @param  array<int, int>  $authorizerUserIds
     */
    public function execute(
        ProformaInvoice $pi,
        User $requester,
        array $authorizerUserIds,
        string $justification,
    ): Conversation {
        $conversation = $this->messaging->createConversation(
            creator: $requester,
            participantUserIds: $authorizerUserIds,
            subject: __('messages.authorization_request_subject', ['reference' => $pi->reference]),
            type: 'request',
            subjectEntity: $pi,
        );

        $body = $this->buildMessageBody($pi, $justification);

        $this->messaging->sendMessage(
            conversation: $conversation,
            sender: $requester,
            body: $body,
        );

        return $conversation;
    }

    private function buildMessageBody(ProformaInvoice $pi, string $justification): string
    {
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($pi);

        $lines = [
            $justification,
            '',
            'Reference: @' . $pi->reference,
            '',
            'Blocking payment items:',
        ];

        foreach ($blockers as $item) {
            $amount = Money::format($item->amount);
            $lines[] = "- {$item->label} — {$item->currency_code} {$amount} ({$item->status->getEnglishLabel()})";
        }

        return implode("\n", $lines);
    }
}
