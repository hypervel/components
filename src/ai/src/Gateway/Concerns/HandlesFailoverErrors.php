<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Closure;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderConnectionException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\RequestException;
use Throwable;

trait HandlesFailoverErrors
{
    /**
     * Execute a callback with failoverable error handling.
     *
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     */
    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return retry(
                2,
                $callback,
                fn (int $attempt, Throwable $exception): int => $this->retryAfterSeconds($exception) * 1000,
                fn (Throwable $exception): bool => $this->retryAfterSeconds($exception) !== null,
            );
        } catch (ConnectionException $connectionException) {
            throw ProviderConnectionException::forProvider(
                $providerName,
                $connectionException->getCode(),
                $connectionException
            );
        } catch (RequestException $requestException) {
            $status = $requestException->response->status();

            if ($this->isInsufficientCredits($requestException)) {
                throw InsufficientCreditsException::forProvider(
                    $providerName,
                    $requestException->getCode(),
                    $requestException
                );
            }

            if ($status === 429) {
                throw RateLimitedException::forProvider(
                    $providerName,
                    $requestException->getCode(),
                    $requestException
                );
            }

            if (in_array($status, $this->overloadedStatusCodes(), true)) {
                throw ProviderOverloadedException::forProvider(
                    $providerName,
                    $requestException->getCode(),
                    $requestException
                );
            }

            $message = $requestException->response->json('error.message');

            if (is_string($message) && $patterns = $this->insufficientCreditPatterns()) {
                $message = strtolower($message);

                foreach ($patterns as $pattern) {
                    if (str_contains($message, (string) $pattern)) {
                        throw InsufficientCreditsException::forProvider(
                            $providerName,
                            $requestException->getCode(),
                            $requestException
                        );
                    }
                }
            }

            throw $requestException;
        }
    }

    /**
     * Get the short Retry-After delay of a rate limited or overloaded response, if it has one.
     */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        if ($this->isInsufficientCredits($exception)) {
            return null;
        }

        $status = $exception->response->status();

        if ($status !== 429 && ! in_array($status, $this->overloadedStatusCodes(), true)) {
            return null;
        }

        $retryAfter = $exception->response->header('Retry-After');

        return ctype_digit($retryAfter) && (int) $retryAfter <= 10 ? (int) $retryAfter : null;
    }

    /**
     * Determine if the response is a 402, or a 429 insufficient credits or quota error rather than a rate limit.
     */
    protected function isInsufficientCredits(RequestException $exception): bool
    {
        $status = $exception->response->status();

        if ($status === 402) {
            return true;
        }

        if ($status !== 429) {
            return false;
        }

        $codes = $this->insufficientCreditCodes();

        return in_array($exception->response->json('error.code'), $codes, true)
            || in_array($exception->response->json('error.type'), $codes, true);
    }

    /**
     * Get the status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [502, 503, 504, 520, 522, 524];
    }

    /**
     * Get the patterns used to detect insufficient credits or quota errors.
     *
     * @return list<string>
     */
    protected function insufficientCreditPatterns(): array
    {
        return [];
    }

    /**
     * Get the error codes or types that indicate insufficient credits or quota on a 429 response.
     *
     * @return list<string>
     */
    protected function insufficientCreditCodes(): array
    {
        // OpenAI sends these with a 429 status, and may still use "insufficient_quota" as the error type.
        return [
            'insufficient_quota',
            'credit_balance_exhausted',
            'organization_spend_limit_exceeded',
            'project_spend_limit_exceeded',
            'organization_usage_limit_exceeded',
        ];
    }
}
