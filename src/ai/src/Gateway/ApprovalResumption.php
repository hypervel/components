<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Responses\Data\ToolResult;

class ApprovalResumption
{
    /**
     * Create the history and results of an approval continuation.
     *
     * @param Message[] $messages
     * @param Message[] $newMessages
     * @param array<int, ToolResult> $results
     */
    public function __construct(
        public array $messages,
        public array $newMessages,
        public array $results,
        public bool $shouldContinue,
    ) {
    }
}
