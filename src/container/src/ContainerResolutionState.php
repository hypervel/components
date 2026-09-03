<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hypervel\Context\ReplicableContext;

/**
 * Mutable state for one container resolution chain.
 *
 * The state is cloned when coroutine context is copied, so parent and child
 * coroutines never share mutable stacks. Entries unwind through `finally`,
 * while allocated capacity lives until the coroutine ends or non-coroutine
 * context is explicitly flushed. Resolution depth is bounded by the Container;
 * build-stack growth from `call()` remains bounded by the PHP call stack.
 *
 * @internal
 */
final class ContainerResolutionState implements ReplicableContext
{
    /**
     * The current nested resolution depth.
     */
    public int $depth = 0;

    /**
     * The concrete types currently being built.
     *
     * @var list<string>
     */
    public array $buildStack = [];

    /**
     * The abstracts currently being resolved.
     *
     * Used exclusively for circular-dependency detection and kept separate
     * from the build stack because call() contributes contextual scope without
     * beginning another container resolution.
     *
     * @var list<string>
     */
    public array $resolvingStack = [];

    /**
     * The parameter overrides for nested builds.
     *
     * @var list<array<array-key, mixed>>
     */
    public array $parameterOverrides = [];

    /**
     * Copy the complete resolution state into an independent object.
     */
    public function replicate(): static
    {
        return clone $this;
    }
}
