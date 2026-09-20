<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency\Fixtures;

use Exception;

class ExceptionWithParam extends Exception
{
    /**
     * Create an exception for the failed API request.
     *
     * @param array<array-key, mixed>|string $responseBody
     */
    public function __construct(
        public string $uri,
        public int $statusCode,
        public string $reason,
        public string|array $responseBody = '',
    ) {
        parent::__construct("API request to {$uri} failed with status {$statusCode} {$reason}");
    }
}
