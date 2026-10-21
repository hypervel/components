<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Closure;
use Hypervel\Saloon\Data\RetryPolicy;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class RetryUserRequest extends Request
{
    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Create a request with a default retry policy.
     *
     * @param int|list<int> $times
     */
    public function __construct(
        protected array|int $times = 1,
        protected Closure|int $sleepMilliseconds = 0,
        protected ?Closure $when = null,
        protected bool $throw = true,
    ) {
    }

    /**
     * Resolve the default retry policy.
     */
    protected function defaultRetryPolicy(): ?RetryPolicy
    {
        return new RetryPolicy($this->times, $this->sleepMilliseconds, $this->when, $this->throw);
    }
}
