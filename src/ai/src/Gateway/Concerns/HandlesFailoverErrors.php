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
            return $callback();
        } catch (ConnectionException $connectionException) {
            throw ProviderConnectionException::forProvider(
                $providerName,
                $connectionException->getCode(),
                $connectionException
            );
        } catch (RequestException $requestException) {
            $status = $requestException->response->status();

            if ($status === 429) {
                throw RateLimitedException::forProvider(
                    $providerName,
                    $requestException->getCode(),
                    $requestException
                );
            }

            if ($status === 402) {
                throw InsufficientCreditsException::forProvider(
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

            if ($patterns = $this->insufficientCreditPatterns()) {
                $message = strtolower($requestException->response->json('error.message', ''));

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
}
