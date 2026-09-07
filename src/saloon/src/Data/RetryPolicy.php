<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Closure;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\PendingRequest;
use InvalidArgumentException;

final readonly class RetryPolicy
{
    /**
     * The delay resolver.
     *
     * @var Closure(int, FatalRequestException|RequestException): int
     */
    public Closure $delay;

    /**
     * The retry condition.
     *
     * @var null|Closure(FatalRequestException|RequestException, PendingRequest): bool
     */
    public ?Closure $when;

    /**
     * Create a retry policy.
     *
     * @param int|list<int> $times
     * @param Closure(int, FatalRequestException|RequestException): int|int $sleepMilliseconds
     * @param null|callable(FatalRequestException|RequestException, PendingRequest): bool $when
     */
    public function __construct(
        public array|int $times = 1,
        Closure|int $sleepMilliseconds = 0,
        ?callable $when = null,
        public bool $throw = true,
    ) {
        if ((is_int($times) && $times < 1) || (is_array($times) && $times === [])) {
            throw new InvalidArgumentException('Retry attempts must contain at least one attempt.');
        }

        foreach (is_array($times) ? $times : [] as $delay) {
            if ($delay < 0) {
                throw new InvalidArgumentException('Retry delays must be non-negative integers.');
            }
        }

        if (is_int($sleepMilliseconds) && $sleepMilliseconds < 0) {
            throw new InvalidArgumentException('The retry delay must be a non-negative integer.');
        }

        $this->delay = is_int($sleepMilliseconds)
            ? static fn (): int => $sleepMilliseconds
            : $sleepMilliseconds;
        $this->when = $when === null ? null : $when(...);
    }

    /**
     * Get the maximum number of attempts.
     */
    public function maximumAttempts(): int
    {
        return is_array($this->times) ? count($this->times) + 1 : $this->times;
    }

    /**
     * Resolve the delay before the next attempt.
     */
    public function delayFor(int $attempt, FatalRequestException|RequestException $exception): int
    {
        $delay = is_array($this->times)
            ? ($this->times[$attempt - 1] ?? 0)
            : ($this->delay)($attempt, $exception);

        if ($delay < 0) {
            throw new InvalidArgumentException('The resolved retry delay must be a non-negative integer.');
        }

        return $delay;
    }
}
