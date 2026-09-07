<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use Override;

/**
 * Store OpenTelemetry context in Hypervel's coroutine-local context.
 */
class CoroutineContextStorage implements ContextStorageInterface, ExecutionContextAwareInterface
{
    public const string CONTEXT_KEY = '__opentelemetry.context';

    protected readonly ContextState $mainState;

    protected ContextState $fallbackState;

    /** @var array<int|string, ContextState> */
    protected array $fallbackStates = [];

    /**
     * Create a coroutine context storage.
     */
    public function __construct(protected readonly ContextInterface $baseContext)
    {
        $this->fallbackState = $this->mainState = new ContextState($baseContext);
    }

    /**
     * Snapshot the current context for another execution unit.
     */
    #[Override]
    public function fork(int|string $id): void
    {
        $this->fallbackStates[$id] = new ContextState($this->current());
    }

    /**
     * Switch to a registered fallback execution unit.
     */
    #[Override]
    public function switch(int|string $id): void
    {
        if (isset($this->fallbackStates[$id])) {
            $this->fallbackState = $this->fallbackStates[$id];

            return;
        }

        $this->fallbackState = $this->mainState;
    }

    /**
     * Forget a registered fallback execution unit.
     */
    #[Override]
    public function destroy(int|string $id): void
    {
        unset($this->fallbackStates[$id]);
    }

    /**
     * Return the active scope.
     */
    #[Override]
    public function scope(): ?ContextStorageScopeInterface
    {
        return $this->existingState()?->scope();
    }

    /**
     * Return the active context.
     */
    #[Override]
    public function current(): ContextInterface
    {
        return $this->existingState()?->current() ?? $this->baseContext;
    }

    /**
     * Attach a context to the current execution unit.
     */
    #[Override]
    public function attach(ContextInterface $context): ContextStorageScopeInterface
    {
        $state = $this->state();
        $scope = new Scope($this, $state, $context);
        $state->attach($scope);

        return $scope;
    }

    /**
     * Determine whether a scope's owning execution unit is active.
     *
     * @internal
     */
    public function isActive(ContextState $state): bool
    {
        return $this->existingState() === $state;
    }

    /**
     * Return or create the current execution unit's state.
     */
    protected function state(): ContextState
    {
        if (! Coroutine::inCoroutine()) {
            return $this->fallbackState;
        }

        $state = CoroutineContext::get(self::CONTEXT_KEY);

        if ($state === null) {
            $state = new ContextState($this->baseContext);
            CoroutineContext::set(self::CONTEXT_KEY, $state);
        }

        return $state;
    }

    /**
     * Return the current state without creating one.
     */
    protected function existingState(): ?ContextState
    {
        if (! Coroutine::inCoroutine()) {
            return $this->fallbackState;
        }

        return CoroutineContext::get(self::CONTEXT_KEY);
    }
}
