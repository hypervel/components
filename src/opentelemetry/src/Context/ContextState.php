<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use Hypervel\Context\ReplicableContext;
use LogicException;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Hold one execution unit's inherited context and locally attached scopes.
 *
 * @internal
 */
class ContextState implements ReplicableContext
{
    protected ?Scope $scope = null;

    /**
     * Create a context state.
     */
    public function __construct(protected readonly ContextInterface $baseContext)
    {
    }

    /**
     * Return the active scope.
     */
    public function scope(): ?Scope
    {
        return $this->scope;
    }

    /**
     * Return the active context.
     */
    public function current(): ContextInterface
    {
        return $this->scope?->context() ?? $this->baseContext;
    }

    /**
     * Push a scope onto this execution unit's stack.
     */
    public function attach(Scope $scope): void
    {
        $scope->linkAfter($this->scope);
        $this->scope = $scope;
    }

    /**
     * Detach a scope from this execution unit's stack.
     */
    public function detach(Scope $scope): int
    {
        if ($scope->isDetached()) {
            return ScopeInterface::DETACHED;
        }

        if ($this->scope === $scope) {
            $this->scope = $scope->unlink();
            $scope->markDetached();

            return 0;
        }

        $depth = 0;
        $current = $this->scope;

        while ($current !== $scope) {
            if ($current === null) {
                throw new LogicException('The OpenTelemetry scope is not attached to its owning context state.');
            }

            $current = $current->previous();
            ++$depth;
        }

        $scope->unlink();
        $scope->markDetached();

        return ScopeInterface::MISMATCH | $depth;
    }

    /**
     * Create an independent child state from the active context.
     */
    public function replicate(): static
    {
        return new static($this->current());
    }
}
