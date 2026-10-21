<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Context\NonCopyableContext;

/**
 * Keep failed user lookups local to the coroutine that resolved them.
 */
readonly class SessionGuardUserMiss implements NonCopyableContext
{
    /**
     * Capture the session state used by a failed user lookup.
     */
    public function __construct(
        public string $sessionId,
        public bool $sessionStarted,
    ) {
    }
}
