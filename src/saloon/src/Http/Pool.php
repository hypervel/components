<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Saloon\Exceptions\InvalidPoolItemException;
use Hypervel\Saloon\Exceptions\PoolException;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\run;

class Pool
{
    /**
     * The requests or request producer.
     *
     * @var Closure(Connector): iterable<array-key, Request>|iterable<array-key, Request>
     */
    protected iterable|Closure $requests;

    /**
     * The successful response handler.
     *
     * @var null|Closure(Response, array-key): void
     */
    protected ?Closure $responseHandler = null;

    /**
     * The request exception handler.
     *
     * @var null|Closure(Throwable, array-key): void
     */
    protected ?Closure $exceptionHandler = null;

    /**
     * Create a request pool.
     *
     * @param callable(Connector): iterable<array-key, Request>|iterable<array-key, Request> $requests
     * @param null|callable(Response, array-key): void $responseHandler
     * @param null|callable(Throwable, array-key): void $exceptionHandler
     */
    public function __construct(
        protected Connector $connector,
        iterable|callable $requests = [],
        protected int $concurrency = 5,
        ?callable $responseHandler = null,
        ?callable $exceptionHandler = null,
    ) {
        $this->setRequests($requests);
        $this->setConcurrency($concurrency);

        if ($responseHandler !== null) {
            $this->withResponseHandler($responseHandler);
        }

        if ($exceptionHandler !== null) {
            $this->withExceptionHandler($exceptionHandler);
        }
    }

    /**
     * Set the pool requests.
     *
     * @param callable(Connector): iterable<array-key, Request>|iterable<array-key, Request> $requests
     * @return $this
     */
    public function setRequests(iterable|callable $requests): static
    {
        $this->requests = is_callable($requests) ? $requests(...) : $requests;

        return $this;
    }

    /**
     * Get the configured requests.
     *
     * @return iterable<array-key, Request>
     */
    public function requests(): iterable
    {
        return $this->requests instanceof Closure
            ? ($this->requests)($this->connector)
            : $this->requests;
    }

    /**
     * Set the maximum number of concurrent requests.
     *
     * @return $this
     */
    public function setConcurrency(int $concurrency): static
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('Pool concurrency must be at least one.');
        }

        $this->concurrency = $concurrency;

        return $this;
    }

    /**
     * Register a successful response handler.
     *
     * @param callable(Response, array-key): void $handler
     * @return $this
     */
    public function withResponseHandler(callable $handler): static
    {
        $this->responseHandler = $handler(...);

        return $this;
    }

    /**
     * Register a request exception handler.
     *
     * @param callable(Throwable, array-key): void $handler
     * @return $this
     */
    public function withExceptionHandler(callable $handler): static
    {
        $this->exceptionHandler = $handler(...);

        return $this;
    }

    /**
     * Send every request and collect successful responses.
     *
     * @return array<array-key, Response>
     */
    public function send(): array
    {
        return $this->execute(collectResponses: true);
    }

    /**
     * Process every request without retaining successful responses.
     */
    public function process(): void
    {
        $this->execute(collectResponses: false);
    }

    /**
     * Execute the pool in the current or a new root coroutine.
     *
     * @return array<array-key, Response>
     */
    protected function execute(bool $collectResponses): array
    {
        if (Coroutine::inCoroutine()) {
            return $this->orchestrate($collectResponses);
        }

        $responses = [];
        $failure = null;

        run(function () use ($collectResponses, &$responses, &$failure): void {
            try {
                $responses = $this->orchestrate($collectResponses);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $responses;
    }

    /**
     * Schedule the requests and settle every started child.
     *
     * @return array<array-key, Response>
     */
    protected function orchestrate(bool $collectResponses): array
    {
        $concurrent = new WaitConcurrent($this->concurrency);
        $responses = [];
        $failures = [];
        $callbackFailures = [];
        $orchestrationFailure = null;
        $position = 0;

        try {
            foreach ($this->requests() as $key => $request) {
                if (! $request instanceof Request) {
                    throw new InvalidPoolItemException;
                }

                $inputPosition = $position++;

                $concurrent->fork(function () use (
                    $request,
                    $key,
                    $inputPosition,
                    $collectResponses,
                    &$responses,
                    &$failures,
                    &$callbackFailures,
                ): void {
                    try {
                        $response = $this->connector->send($request);
                    } catch (CanceledException $exception) {
                        $failures[$inputPosition] = [$key, new ChildCancellationException(
                            'A child coroutine running a Saloon pool request was canceled while its owner remained active.',
                            previous: $exception,
                        )];

                        return;
                    } catch (Throwable $exception) {
                        if ($this->exceptionHandler === null) {
                            $failures[$inputPosition] = [$key, $exception];
                        } else {
                            try {
                                ($this->exceptionHandler)($exception, $key);
                            } catch (CanceledException $callbackException) {
                                $failures[$inputPosition] = [$key, $exception];
                                $callbackFailures[$inputPosition] = [$key, new ChildCancellationException(
                                    'A child coroutine running a Saloon pool callback was canceled while its owner remained active.',
                                    previous: $callbackException,
                                )];
                            } catch (Throwable $callbackException) {
                                $failures[$inputPosition] = [$key, $exception];
                                $callbackFailures[$inputPosition] = [$key, $callbackException];
                            }
                        }

                        return;
                    }

                    if ($collectResponses) {
                        $responses[$inputPosition] = [$key, $response];
                    }

                    try {
                        $this->responseHandler?->__invoke($response, $key);
                    } catch (CanceledException $callbackException) {
                        $callbackFailures[$inputPosition] = [$key, new ChildCancellationException(
                            'A child coroutine running a Saloon pool callback was canceled while its owner remained active.',
                            previous: $callbackException,
                        )];
                    } catch (Throwable $callbackException) {
                        $callbackFailures[$inputPosition] = [$key, $callbackException];
                    }
                });
            }
        } catch (CanceledException $exception) {
            $concurrent->cancel();
            throw $exception;
        } catch (Throwable $exception) {
            $orchestrationFailure = $exception;
        }

        $concurrent->wait();

        $keyedResponses = $this->keyResults($responses);
        $keyedFailures = $this->keyResults($failures);
        $keyedCallbackFailures = $this->keyResults($callbackFailures);

        if ($orchestrationFailure !== null || $keyedFailures !== [] || $keyedCallbackFailures !== []) {
            throw new PoolException(
                $orchestrationFailure,
                $keyedFailures,
                $keyedCallbackFailures,
                $keyedResponses,
            );
        }

        return $keyedResponses;
    }

    /**
     * Rebuild results in input order using normal PHP key semantics.
     *
     * @template TValue
     * @param array<int, array{array-key, TValue}> $results
     * @return array<array-key, TValue>
     */
    protected function keyResults(array $results): array
    {
        ksort($results);
        $keyed = [];

        foreach ($results as [$key, $value]) {
            $keyed[$key] = $value;
        }

        return $keyed;
    }
}
