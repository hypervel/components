<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ErrorHandlingTest extends TestCase
{
    public function testHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'max_tokens: must be at least 1',
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    public function testRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    #[DataProvider('insufficientCreditMessages')]
    public function testInsufficientCreditResponseThrowsInsufficientCreditsException(string $message): void
    {
        $this->expectException(InsufficientCreditsException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => $message,
                ],
            ], 400),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    /**
     * Provide insufficient credit error messages.
     */
    public static function insufficientCreditMessages(): array
    {
        return [
            'credit balance' => ['Your credit balance is too low to access the API.'],
            'insufficient' => ['You have insufficient funds to complete this request.'],
            'quota exceeded' => ['Your monthly quota exceeded the configured limit.'],
            'exceeded your current quota' => ['You have exceeded your current quota, please check your plan.'],
            'billing' => ['There is a billing issue with your account; please update your payment method.'],
            'usage limit' => ['You have reached your specified API usage limits. To continue, please adjust your limits.'],
        ];
    }

    public function testErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('api_error');
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    public function test529OverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'overloaded_error',
                    'message' => 'Overloaded',
                ],
            ], 529),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    #[DataProvider('transientStatusCodes')]
    public function testTransientUpstreamErrorsFailOverAsOverloaded(int $status): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'The service is temporarily unavailable.',
                ],
            ], $status),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    /**
     * Provide transient upstream status codes.
     */
    public static function transientStatusCodes(): array
    {
        return [[502], [503], [504], [520], [522], [524]];
    }
}
