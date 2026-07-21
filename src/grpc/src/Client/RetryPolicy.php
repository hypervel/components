<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Hypervel\Grpc\StatusCode;
use InvalidArgumentException;

final readonly class RetryPolicy
{
    /**
     * @param list<StatusCode> $retryableStatusCodes
     */
    public function __construct(
        public int $maxAttempts,
        public float $initialBackoff = 0.1,
        public float $maxBackoff = 5.0,
        public float $backoffMultiplier = 2.0,
        public array $retryableStatusCodes = [StatusCode::Unavailable],
    ) {
        if ($this->maxAttempts < 2) {
            throw new InvalidArgumentException('A gRPC retry policy requires at least two attempts.');
        }

        if (! is_finite($this->initialBackoff) || $this->initialBackoff <= 0) {
            throw new InvalidArgumentException('The initial gRPC retry backoff must be positive and finite.');
        }

        if (! is_finite($this->maxBackoff) || $this->maxBackoff <= 0) {
            throw new InvalidArgumentException('The maximum gRPC retry backoff must be positive and finite.');
        }

        if ($this->maxBackoff < $this->initialBackoff) {
            throw new InvalidArgumentException(
                'The maximum gRPC retry backoff cannot be shorter than the initial backoff.',
            );
        }

        if (! is_finite($this->backoffMultiplier) || $this->backoffMultiplier < 1) {
            throw new InvalidArgumentException('The gRPC retry backoff multiplier must be at least one.');
        }

        if ($this->retryableStatusCodes === [] || ! array_is_list($this->retryableStatusCodes)) {
            throw new InvalidArgumentException('A gRPC retry policy requires a list of retryable status codes.');
        }

        $codes = [];

        foreach ($this->retryableStatusCodes as $code) {
            if (! $code instanceof StatusCode) {
                throw new InvalidArgumentException('Every retryable gRPC status code must be a StatusCode.');
            }

            if ($code === StatusCode::Ok) {
                throw new InvalidArgumentException('A successful gRPC status cannot be retryable.');
            }

            if (isset($codes[$code->value])) {
                throw new InvalidArgumentException('Retryable gRPC status codes must be unique.');
            }

            $codes[$code->value] = true;
        }
    }
}
