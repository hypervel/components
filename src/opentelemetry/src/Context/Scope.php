<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;
use Override;

/**
 * Represent one context attachment owned by a single execution unit.
 *
 * @internal
 */
class Scope implements ContextStorageScopeInterface
{
    /** @var array<array-key, mixed> */
    protected array $localStorage = [];

    protected ?self $previous = null;

    protected ?self $next = null;

    protected bool $detached = false;

    /**
     * Create a context scope.
     */
    public function __construct(
        protected readonly CoroutineContextStorage $storage,
        protected readonly ContextState $state,
        protected readonly ContextInterface $context,
    ) {
    }

    /**
     * Determine whether local scope storage contains an offset.
     */
    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->localStorage[$offset]);
    }

    /**
     * Return a value from local scope storage.
     */
    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->localStorage[$offset];
    }

    /**
     * Store a value in local scope storage.
     */
    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->localStorage[$offset] = $value;
    }

    /**
     * Remove a value from local scope storage.
     */
    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        unset($this->localStorage[$offset]);
    }

    /**
     * Return the context attached by this scope.
     */
    #[Override]
    public function context(): ContextInterface
    {
        return $this->context;
    }

    /**
     * Detach this scope from its owning execution unit.
     */
    #[Override]
    public function detach(): int
    {
        $flags = $this->storage->isActive($this->state)
            ? 0
            : ScopeInterface::INACTIVE;

        return $flags | $this->state->detach($this);
    }

    /**
     * Link this scope after the previous active scope.
     */
    public function linkAfter(?self $previous): void
    {
        $this->previous = $previous;

        if ($previous !== null) {
            $previous->next = $this;
        }
    }

    /**
     * Unlink this scope and return its previous scope.
     */
    public function unlink(): ?self
    {
        $previous = $this->previous;

        if ($previous !== null) {
            $previous->next = $this->next;
        }

        if ($this->next !== null) {
            $this->next->previous = $previous;
        }

        $this->previous = null;
        $this->next = null;

        return $previous;
    }

    /**
     * Return the previous scope in the stack.
     */
    public function previous(): ?self
    {
        return $this->previous;
    }

    /**
     * Determine whether this scope was already detached.
     */
    public function isDetached(): bool
    {
        return $this->detached;
    }

    /**
     * Mark this scope as detached.
     */
    public function markDetached(): void
    {
        $this->detached = true;
    }
}
