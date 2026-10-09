<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Storage\StoredTurn;
use Throwable;

interface StoresConversationTurns
{
    /**
     * Persist a complete turn atomically, creating its conversation when a title is supplied.
     */
    public function storeTurn(
        string $conversationId,
        ?string $newConversationTitle,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): StoredTurn;
}
