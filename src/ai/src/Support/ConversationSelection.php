<?php

declare(strict_types=1);

namespace Hypervel\Ai\Support;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\RemembersConversations;
use Hypervel\Ai\Middleware\RememberConversation;

/**
 * @internal
 */
class ConversationSelection
{
    /**
     * Capture the conversation an invocation belongs to.
     */
    public function __construct(
        public ?string $conversationId,
        public readonly ?object $participant,
    ) {
    }

    /**
     * Capture the selection of an agent that remembers conversations.
     */
    public static function forAgent(Agent $agent): ?self
    {
        if (! RememberConversation::appliesTo($agent)) {
            return null;
        }

        /** @var Agent&RemembersConversations $agent */
        return new self($agent->currentConversation(), $agent->conversationParticipant());
    }
}
