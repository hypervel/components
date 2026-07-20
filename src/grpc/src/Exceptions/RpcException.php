<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Exceptions;

use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use InvalidArgumentException;

class RpcException extends GrpcException
{
    private Status $status;

    private Metadata $metadata;

    private Metadata $trailers;

    private ?string $method = null;

    private ?string $target = null;

    private ?int $retryPushbackMilliseconds = null;

    public function __construct(StatusCode $code, string $message = '')
    {
        if ($code === StatusCode::Ok) {
            throw new InvalidArgumentException('An RPC exception requires a non-OK gRPC status.');
        }

        parent::__construct($message, $code->value);

        $this->status = new Status($code, $message);
        $this->metadata = Metadata::make();
        $this->trailers = Metadata::make();
    }

    /**
     * Create an exception from rich error details.
     */
    public static function fromStatus(RichStatus $status): self
    {
        $code = StatusCode::tryFrom($status->getCode());

        if ($code === null || $code === StatusCode::Ok) {
            throw new InvalidArgumentException('Rich RPC error details require a defined non-OK gRPC status.');
        }

        $exception = new self($code, $status->getMessage());
        $exception->status = new Status($code, $status->getMessage(), $status);

        return $exception;
    }

    /**
     * Create an exception for a completed client call.
     *
     * @internal
     */
    public static function fromCall(
        Status $status,
        Metadata $metadata,
        Metadata $trailers,
        string $method,
        string $target,
    ): self {
        if ($status->isOk()) {
            throw new InvalidArgumentException('A successful call cannot produce an RPC exception.');
        }

        $exception = new self($status->code(), $status->message());
        $exception->status = $status;
        $exception->metadata = $metadata;
        $exception->trailers = $trailers;
        $exception->method = $method;
        $exception->target = $target;

        return $exception;
    }

    /**
     * Append custom trailing metadata.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     */
    public function withTrailingMetadata(Metadata|array $metadata): self
    {
        $copy = $this->copy();
        $copy->trailers = $copy->trailers->merge($metadata);

        return $copy;
    }

    /**
     * Request a retry delay in seconds.
     */
    public function withRetryAfter(float $seconds): self
    {
        $copy = $this->copy();
        $copy->retryPushbackMilliseconds = self::millisecondsFor($seconds);

        return $copy;
    }

    /**
     * Prevent the client from retrying the failure.
     */
    public function withoutRetry(): self
    {
        $copy = $this->copy();
        $copy->retryPushbackMilliseconds = -1;

        return $copy;
    }

    /**
     * Return the gRPC status.
     */
    public function status(): Status
    {
        return $this->status;
    }

    /**
     * Return the initial response metadata.
     */
    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * Return the trailing response metadata.
     */
    public function trailers(): Metadata
    {
        return $this->trailers;
    }

    /**
     * Return the service method path.
     */
    public function method(): ?string
    {
        return $this->method;
    }

    /**
     * Return the connection target.
     */
    public function target(): ?string
    {
        return $this->target;
    }

    /**
     * Return the retry pushback in milliseconds.
     *
     * @internal
     */
    public function retryPushbackMilliseconds(): ?int
    {
        return $this->retryPushbackMilliseconds;
    }

    /**
     * Create an independent fluent copy.
     */
    private function copy(): self
    {
        // Throwable objects cannot be cloned. Reconstruction intentionally starts a new
        // library trace; keep this complete transfer in sync with any state or cause added.
        $copy = new self($this->status->code(), $this->getMessage());
        $copy->status = $this->status;
        $copy->metadata = $this->metadata;
        $copy->trailers = $this->trailers;
        $copy->method = $this->method;
        $copy->target = $this->target;
        $copy->retryPushbackMilliseconds = $this->retryPushbackMilliseconds;

        return $copy;
    }

    /**
     * Convert seconds to an upward-rounded, representable millisecond count.
     */
    private static function millisecondsFor(float $seconds): int
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('The retry delay must be a non-negative finite number of seconds.');
        }

        $maximumWholeSeconds = intdiv(PHP_INT_MAX, 1000);
        $wholeSeconds = floor($seconds);

        if ($wholeSeconds > $maximumWholeSeconds) {
            throw new InvalidArgumentException('The retry delay exceeds the supported millisecond range.');
        }

        $milliseconds = (int) $wholeSeconds * 1000;
        $fractionalMilliseconds = (int) ceil(($seconds - $wholeSeconds) * 1000);

        if ($milliseconds > PHP_INT_MAX - $fractionalMilliseconds) {
            throw new InvalidArgumentException('The retry delay exceeds the supported millisecond range.');
        }

        return $milliseconds + $fractionalMilliseconds;
    }
}
