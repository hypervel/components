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
use Hypervel\Support\Sleep;
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

    #[DataProvider('retryableStatuses')]
    public function testARateLimitedOrOverloadedRequestIsRetriedOnceAfterItsRetryAfterDelay(int $status): void
    {
        Sleep::fake();

        $attempts = 0;

        $result = $this->withErrorHandling('anthropic', function () use ($status, &$attempts): string {
            if (++$attempts === 1) {
                throw $this->failoverableException($status, headers: ['Retry-After' => '2']);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts);

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    /**
     * Provide the rate limited and overloaded statuses that honor Retry-After.
     */
    public static function retryableStatuses(): array
    {
        return [[429], [529]];
    }

    public function testARequestThatIsStillRateLimitedAfterTheRetryFailsOver(): void
    {
        Sleep::fake();

        $attempts = 0;

        $request = function () use (&$attempts): never {
            ++$attempts;

            throw $this->failoverableException(429, headers: ['Retry-After' => '1']);
        };

        try {
            $this->withErrorHandling('anthropic', $request);
            $this->fail('Expected the rate limited exception.');
        } catch (RateLimitedException) {
        }

        $this->assertSame(2, $attempts);

        Sleep::assertSleptTimes(1);
    }

    #[DataProvider('unretriedFailures')]
    public function testARequestIsNotRetriedWithoutAShortRetryAfterOnAFailoverableStatus(int $status, array $headers): void
    {
        Sleep::fake();

        $attempts = 0;

        $request = function () use ($status, $headers, &$attempts): never {
            ++$attempts;

            throw $this->failoverableException($status, headers: $headers);
        };

        try {
            $this->withErrorHandling('anthropic', $request);
            $this->fail('Expected the request to fail.');
        } catch (RateLimitedException|RequestException) {
        }

        $this->assertSame(1, $attempts);

        Sleep::assertNeverSlept();
    }

    /**
     * Provide failures without a short whole-second Retry-After.
     */
    public static function unretriedFailures(): array
    {
        return [
            'no header' => [429, []],
            'long delay' => [429, ['Retry-After' => '60']],
            'http date' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
            'fractional delay' => [429, ['Retry-After' => '1.5']],
            'client error' => [400, ['Retry-After' => '1']],
        ];
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
    protected function failoverableException(int $status, array $body = [], array $headers = []): RequestException
    {
        $response = new Psr7Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body));

        return new RequestException(new Response($response));
    }
}
