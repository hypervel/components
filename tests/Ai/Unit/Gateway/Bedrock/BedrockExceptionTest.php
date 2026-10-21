<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use Aws\Exception\CredentialsException;
use Aws\Sts\Exception\StsException;
use Exception;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderConnectionException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Gateway\Bedrock\BedrockException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

function mockBedrockException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
{
    return new class($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException {
        /**
         * Create a service exception with the specified error details.
         */
        public function __construct(
            private string $awsErrorCode,
            private int $httpStatus,
            string $message,
        ) {
            Exception::__construct($message, $httpStatus);
        }

        /**
         * Get the AWS service error code.
         */
        public function getAwsErrorCode(): string
        {
            return $this->awsErrorCode;
        }

        /**
         * Get the HTTP response status.
         */
        public function getStatusCode(): int
        {
            return $this->httpStatus;
        }
    };
}

class BedrockExceptionTest extends TestCase
{
    public function testThrottlingMapsToRateLimitedException(): void
    {
        $bedrock = mockBedrockException('ThrottlingException', 429);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(RateLimitedException::class, $aiException);
        $this->assertSame(429, $aiException->getCode());
        $this->assertSame($bedrock, $aiException->getPrevious());
    }

    public function testServiceUnavailableMapsToProviderOverloaded(): void
    {
        $bedrock = mockBedrockException('ServiceUnavailableException', 503);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(ProviderOverloadedException::class, $aiException);
        $this->assertSame(503, $aiException->getCode());
    }

    public function testModelNotReadyMapsToProviderOverloaded(): void
    {
        $bedrock = mockBedrockException('ModelNotReadyException', 503);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(ProviderOverloadedException::class, $aiException);
    }

    public function testModelTimeoutMapsToProviderOverloaded(): void
    {
        $bedrock = mockBedrockException('ModelTimeoutException', 408);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(ProviderOverloadedException::class, $aiException);
    }

    public function testModelStreamErrorMapsToProviderOverloaded(): void
    {
        $bedrock = mockBedrockException('ModelStreamErrorException', 424);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(ProviderOverloadedException::class, $aiException);
    }

    public function testInternalServerErrorMapsToProviderOverloaded(): void
    {
        $bedrock = mockBedrockException('InternalServerException', 500);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(ProviderOverloadedException::class, $aiException);
    }

    public function testServiceQuotaExceededMapsToInsufficientCredits(): void
    {
        $bedrock = mockBedrockException('ServiceQuotaExceededException', 402);

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(InsufficientCreditsException::class, $aiException);
        $this->assertSame(402, $aiException->getCode());
    }

    public function testUnknownBedrockErrorFallsThroughToGenericAiException(): void
    {
        $bedrock = mockBedrockException('SomethingElseException', 400, 'weird error');

        $aiException = BedrockException::toAiException($bedrock, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(AiException::class, $aiException);
        $this->assertNotInstanceOf(RateLimitedException::class, $aiException);
        $this->assertStringContainsString('bedrock', $aiException->getMessage());
        $this->assertStringContainsString('weird error', $aiException->getMessage());
    }

    public function testNonBedrockExceptionWithCreditBalanceMessageMapsToInsufficientCredits(): void
    {
        $generic = new RuntimeException('Your credit balance is too low');

        $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(InsufficientCreditsException::class, $aiException);
    }

    public function testNonBedrockExceptionWithQuotaExceededMessageMapsToInsufficientCredits(): void
    {
        $generic = new RuntimeException('You have exceeded your current quota');

        $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(InsufficientCreditsException::class, $aiException);
    }

    public function testNonBedrockGenericExceptionWrapsAsAiException(): void
    {
        $generic = new RuntimeException('Some network failure', 500);

        $aiException = BedrockException::toAiException($generic, 'bedrock', 'claude-sonnet');

        $this->assertInstanceOf(AiException::class, $aiException);
        $this->assertNotInstanceOf(InsufficientCreditsException::class, $aiException);
        $this->assertSame('Some network failure', $aiException->getMessage());
        $this->assertSame($generic, $aiException->getPrevious());
    }

    #[DataProvider('connectionFailures')]
    public function testConnectionFailuresAllowProviderFailover(Throwable $failure): void
    {
        $converted = BedrockException::toAiException($failure, 'bedrock', 'model');

        $this->assertInstanceOf(ProviderConnectionException::class, $converted);
        $this->assertSame($failure, $converted->getPrevious());
    }

    /**
     * Provide connection failures from generation and credential refresh.
     */
    public static function connectionFailures(): array
    {
        return [
            'bedrock' => [new BedrockRuntimeException('Connection refused', new Command('Converse'), ['connection_error' => true])],
            'assume role' => [new CredentialsException(
                'Error in retrieving assume role credentials.',
                0,
                new StsException('Connection refused', new Command('AssumeRole'), ['connection_error' => true])
            )],
        ];
    }

    public function testCancellationIsNotConvertedToAnAiError(): void
    {
        $cancellation = new CanceledException('Request canceled.');
        $caught = null;

        try {
            BedrockException::toAiException($cancellation, 'bedrock', 'model');
        } catch (CanceledException $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }
}
