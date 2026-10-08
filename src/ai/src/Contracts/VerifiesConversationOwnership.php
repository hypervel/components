<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

interface VerifiesConversationOwnership
{
    /**
     * Determine whether the given conversation was stored for the given participant.
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool;
}
