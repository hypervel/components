<?php

declare(strict_types=1);

namespace Hypervel\Ai\Storage;

readonly class StoredTurn
{
    /**
     * Identify the conversation and messages committed for a turn.
     */
    public function __construct(
        public string $conversationId,
        public ?string $userMessageId,
        public ?string $assistantMessageId,
    ) {
    }
}
