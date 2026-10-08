<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Approvals\PendingApproval;

interface ResolvesPendingApprovals
{
    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array;
}
