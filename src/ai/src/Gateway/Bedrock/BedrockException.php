<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderConnectionException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Support\Str;
use Swoole\Coroutine\CanceledException;
use Throwable;

class BedrockException
{
    /**
     * Patterns that indicate an insufficient credits or quota error.
     *
     * @var list<string>
     */
    protected static array $insufficientCreditPatterns = [
        'credit balance',
        'insufficient',
        'quota exceeded',
        'exceeded your current quota',
        'billing',
        'service quota',
    ];

    /**
     * Create a new AI exception from an AWS Bedrock exception.
     *
     * @throws CanceledException
     */
    public static function toAiException(Throwable $e, string $provider, string $model): AiException
    {
        if ($e instanceof CanceledException) {
            throw $e;
        }

        $cause = $e instanceof CredentialsException ? $e->getPrevious() : $e;

        if ($cause instanceof AwsException && $cause->isConnectionError()) {
            return ProviderConnectionException::forProvider($provider, previous: $e);
        }

        if ($e instanceof BedrockRuntimeException) {
            return match ($e->getAwsErrorCode()) {
                'ThrottlingException' => RateLimitedException::forProvider($provider, $e->getStatusCode(), $e),
                'ServiceUnavailableException',
                'ModelNotReadyException',
                'ModelTimeoutException',
                'ModelStreamErrorException',
                'InternalServerException' => new ProviderOverloadedException(
                    'AI provider [' . $provider . '] is overloaded or unavailable.',
                    code: $e->getStatusCode(),
                    previous: $e,
                ),
                'ServiceQuotaExceededException' => InsufficientCreditsException::forProvider($provider, $e->getStatusCode(), $e),
                default => new AiException(
                    'AWS Bedrock error for provider [' . $provider . ']: ' . $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e,
                ),
            };
        }

        if (static::isInsufficientCreditsError($e)) {
            return InsufficientCreditsException::forProvider($provider, $e->getCode(), $e);
        }

        return new AiException(
            $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
        );
    }

    /**
     * Determine if the given exception indicates an insufficient credits or quota error.
     */
    protected static function isInsufficientCreditsError(Throwable $e): bool
    {
        return Str::contains(strtolower($e->getMessage()), static::$insufficientCreditPatterns);
    }
}
