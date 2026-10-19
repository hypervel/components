<?php

declare(strict_types=1);

namespace Hypervel\Ai\Middleware;

use Closure;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\PendingStep;

class Stop
{
    /**
     * Create a new stop middleware instance.
     *
     * @param bool|(Closure(PendingStep): bool) $condition
     */
    public function __construct(protected Closure|bool $condition)
    {
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
        return value($this->condition, $step) ? StepResult::stop() : $next($step);
    }
}
