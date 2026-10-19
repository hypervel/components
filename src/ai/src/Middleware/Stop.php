<?php

declare(strict_types=1);

namespace Hypervel\Ai\Middleware;

use Closure;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\PendingStep;
use Laravel\SerializableClosure\SerializableClosure;

class Stop
{
    /**
     * The stop condition, wrapped when it is a closure so queued agents and listeners can serialize the middleware.
     */
    protected SerializableClosure|bool $condition;

    /**
     * Create a new stop middleware instance.
     *
     * @param bool|(Closure(PendingStep): bool) $condition
     */
    public function __construct(Closure|bool $condition)
    {
        $this->condition = $condition instanceof Closure ? new SerializableClosure($condition) : $condition;
    }

    /**
     * Stop the run before any step for which the given condition holds.
     *
     * @param bool|(Closure(PendingStep): bool) $condition
     */
    public static function when(Closure|bool $condition): self
    {
        return new self($condition);
    }

    /**
     * Stop the run before any step for which the given condition fails.
     *
     * @param bool|(Closure(PendingStep): bool) $condition
     */
    public static function unless(Closure|bool $condition): self
    {
        return new self(fn (PendingStep $step): bool => ! value($condition, $step));
    }

    /**
     * Handle the pending step.
     *
     * @param Closure(PendingStep): StepResult $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $stop = $this->condition instanceof SerializableClosure
            ? ($this->condition)($step)
            : $this->condition;

        return $stop ? StepResult::stop() : $next($step);
    }
}
