<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Closure;
use Hypervel\Saloon\Data\RetryPolicy;

class RetryConnector extends TestConnector
{
    /**
     * Create a connector with a default retry policy.
     *
     * @param int|list<int> $times
     */
    public function __construct(
        protected array|int $times = 1,
        protected Closure|int $sleepMilliseconds = 0,
        protected ?Closure $when = null,
        protected bool $throw = true,
        ?string $url = null,
    ) {
        parent::__construct($url);
    }

    /**
     * Resolve the default retry policy.
     */
    protected function defaultRetryPolicy(): ?RetryPolicy
    {
        return new RetryPolicy($this->times, $this->sleepMilliseconds, $this->when, $this->throw);
    }
}
