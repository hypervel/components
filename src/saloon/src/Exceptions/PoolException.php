<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Hypervel\Saloon\Http\Response;
use Throwable;

class PoolException extends SaloonException
{
    /**
     * Create a pool exception.
     *
     * @param array<array-key, Throwable> $failures
     * @param array<array-key, Throwable> $callbackFailures
     * @param array<array-key, Response> $responses
     */
    public function __construct(
        protected ?Throwable $orchestrationFailure,
        protected array $failures,
        protected array $callbackFailures,
        protected array $responses,
    ) {
        parent::__construct(
            'One or more requests or callbacks failed while processing the Saloon pool.',
            previous: $orchestrationFailure,
        );
    }

    /**
     * Get the failure that interrupted pool orchestration.
     */
    public function orchestrationFailure(): ?Throwable
    {
        return $this->orchestrationFailure;
    }

    /**
     * Get the unhandled request failures.
     *
     * @return array<array-key, Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Get the response and exception callback failures.
     *
     * @return array<array-key, Throwable>
     */
    public function callbackFailures(): array
    {
        return $this->callbackFailures;
    }

    /**
     * Get the successful partial responses.
     *
     * @return array<array-key, Response>
     */
    public function responses(): array
    {
        return $this->responses;
    }
}
