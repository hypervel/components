<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Support\Collection;

class ToolApprovalRequested
{
    /**
     * Create an event for tools awaiting approval.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Collection $pendingApprovals,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {
    }
}
