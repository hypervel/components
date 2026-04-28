<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

abstract class AbstractAspect implements AroundInterface
{
    /**
     * The classes to weave.
     *
     * Supports exact class names and wildcards:
     * - 'App\Service\UserService' — all methods
     * - 'App\Service\UserService::getUser' — specific method
     * - 'App\Service\*' — wildcard class matching
     * - 'App\Service\UserService::get*' — wildcard method matching
     */
    public array $classes = [];

    /**
     * The aspect priority.
     *
     * Higher priority aspects execute first (outer layers of the pipeline).
     */
    public ?int $priority = null;
}
