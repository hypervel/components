<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Support\Collection;

class ToolApprovalRequest extends StreamEvent
{
    /**
     * Create a tool approval request event.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     * @param Collection<int, Step> $steps replay state for the paused turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public Collection $pendingApprovals,
        public int $timestamp,
        public Collection $steps = new Collection,
    ) {
    }

    /**
     * Get the event as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_approval_request',
            'approvals' => $this->pendingApprovals->values()->toArray(),
            'timestamp' => $this->timestamp,
        ];
    }
}
