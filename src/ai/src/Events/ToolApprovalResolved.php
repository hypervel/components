<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Support\Collection;

class ToolApprovalResolved
{
    /**
     * Create an event for resolved tool approvals.
     *
     * @param Collection<int, ToolResult> $toolResults
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Collection $toolResults,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {
    }
}
