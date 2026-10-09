<?php

declare(strict_types=1);

namespace Hypervel\Ai\Approvals;

readonly class ApprovalClaim
{
    /**
     * Identify the paused message and the operation that owns its continuation.
     */
    public function __construct(
        public string $messageId,
        public string $token,
    ) {
    }
}
