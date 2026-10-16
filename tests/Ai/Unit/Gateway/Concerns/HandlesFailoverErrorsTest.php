<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HandlesFailoverErrorsTest extends TestCase
{
    use HandlesFailoverErrors;

    #[DataProvider('overloadStatuses')]
    public function testOverridingOverloadedStatusCodesReplacesTheDefault503(int $status, string $exception): void
    {
        $this->expectException($exception);

        $this->withErrorHandling('anthropic', fn (): never => throw $this->failoverableException($status));
    }

    /**
     * Provide the overridden and default overload statuses.
     */
    public static function overloadStatuses(): array
    {
        return [[529, ProviderOverloadedException::class], [503, RequestException::class]];
    }

    public function test429TakesPrecedenceOverInsufficientCreditPatternMatching(): void
    {
        $this->expectException(RateLimitedException::class);

        $this->withErrorHandling('anthropic', fn (): never => throw $this->failoverableException(429, [
            'error' => ['message' => 'Your credit balance is too low.'],
        ]));
    }

    public function test402ThrowsInsufficientCreditsExceptionWithoutRequiringPatterns(): void
    {
        $gateway = new class {
            use HandlesFailoverErrors {
                withErrorHandling as public;
            }
        };

        $this->expectException(InsufficientCreditsException::class);

        $gateway->withErrorHandling('deepseek', fn (): never => throw $this->failoverableException(402, [
            'error' => ['message' => 'Insufficient Balance'],
        ]));
    }

    public function testNonMatchingMessageIsRethrownAsTheOriginalRequestException(): void
    {
        $failure = $this->failoverableException(400, [
            'error' => ['message' => 'invalid prompt'],
        ]);

        try {
            $this->withErrorHandling('anthropic', static fn (): never => throw $failure);
            $this->fail('Expected the original request exception.');
        } catch (RequestException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Get the provider-specific overload status.
     */
    protected function overloadedStatusCodes(): array
    {
        return [529];
    }

    /**
     * Get the provider-specific credit error patterns.
     */
    protected function insufficientCreditPatterns(): array
    {
        return ['credit balance', 'quota exceeded'];
    }

    /**
     * Create an HTTP request exception with the given response.
     */
    protected function failoverableException(int $status, array $body = []): RequestException
    {
        $response = new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body));

        return new RequestException(new Response($response));
    }
}
