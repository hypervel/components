<?php

declare(strict_types=1);

namespace Hypervel\Ai\Approvals;

class Approval
{
    /**
     * Create a new approval requirement.
     */
    public function __construct(public readonly ?string $reason = null)
    {
    }

    /**
     * Create a required approval.
     */
    public static function required(?string $reason = null): self
    {
        return new self($reason);
    }
}
