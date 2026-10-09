<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Responses\Data\ToolResult;

interface ClaimsPendingApprovals
{
    /**
     * Atomically claim a paused turn with this exact pending call set, or return null if unavailable.
     *
     * @param list<string> $toolCallIds
     */
    public function claimPendingApprovals(string $conversationId, array $toolCallIds): ?ApprovalClaim;

    /**
     * Durably record one result after verifying ownership of the claimed turn.
     */
    public function recordApprovalResult(ApprovalClaim $claim, ToolResult $result): void;
}
