<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\Providers\TextProvider;

abstract class Prompt
{
    /**
     * Create a text prompt.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?Decisions $approvalDecisions = null,
    ) {
    }

    /**
     * Determine whether the prompt has tool approval decisions.
     */
    public function hasApprovalDecisions(): bool
    {
        return $this->approvalDecisions !== null;
    }
}
