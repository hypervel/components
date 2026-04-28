<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Retry policy for the Meilisearch HTTP client.
 *
 * The meilisearch/meilisearch-php client has no built-in retry mechanism
 * (unlike Algolia's PHP client which has host failover, and Typesense's
 * which has num_retries). Hypervel adds HTTP-level retry at the Guzzle
 * handler-stack layer for parity, using this policy to decide which
 * responses or exceptions warrant a retry and how long to wait.
 */
class MeilisearchRetryPolicy
{
    /**
     * Status codes that indicate transient failures and should be retried.
     */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * Determine whether a response or exception should trigger a retry.
     */
    public static function shouldRetry(?ResponseInterface $response, ?Throwable $exception): bool
    {
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response !== null) {
            return in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, true);
        }

        return false;
    }

    /**
     * Calculate the delay in milliseconds before the given retry attempt.
     *
     * Attempts are 1-based: the first retry waits $baseDelayMs, the second
     * waits twice that, and so on (exponential backoff).
     */
    public static function nextDelayMs(int $attempt, int $baseDelayMs): int
    {
        return $baseDelayMs * (2 ** ($attempt - 1));
    }

    /**
     * Build a Guzzle retry middleware configured with this policy.
     */
    public static function middleware(int $maxRetries, int $baseDelayMs): callable
    {
        return Middleware::retry(
            function (int $retriesDone, RequestInterface $request, ?ResponseInterface $response, ?Throwable $exception) use ($maxRetries): bool {
                if ($retriesDone >= $maxRetries) {
                    return false;
                }
                return self::shouldRetry($response, $exception);
            },
            function (int $attempt) use ($baseDelayMs): int {
                return self::nextDelayMs($attempt, $baseDelayMs);
            },
        );
    }
}
