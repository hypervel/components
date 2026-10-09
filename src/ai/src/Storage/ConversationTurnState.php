<?php

declare(strict_types=1);

namespace Hypervel\Ai\Storage;

use Hypervel\Context\NonCopyableContext;

class ConversationTurnState implements NonCopyableContext
{
    public bool $touched = false;

    /**
     * Track the parent write within a store-owned transaction.
     */
    public function __construct(
        public readonly DatabaseConversationStore $store,
        public string $conversationId,
    ) {
    }
}
