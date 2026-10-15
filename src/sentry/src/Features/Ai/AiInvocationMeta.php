<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

/**
 * @internal
 */
class AiInvocationMeta
{
    /**
     * Retain only metadata reused by later model steps.
     */
    public function __construct(
        public readonly string $agentName,
        public ?string $toolDefinitions = null,
    ) {
    }
}
