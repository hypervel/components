<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use ErrorException;
use Exception;
use Hypervel\Concurrency\SerializedClosureResult;
use Hypervel\Support\Json;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\TestCase;
use RuntimeException;
use TypeError;

class SerializedClosureResultTest extends TestCase
{
    public function testItDecodesBinaryAndFalseResultsLosslessly(): void
    {
        $this->assertSame(
            "binary-\xFF\x00\x8B",
            $this->decodeResult("binary-\xFF\x00\x8B")
        );
        $this->assertFalse($this->decodeResult(false));
    }

    public function testItPreservesPublicFalseyExceptionParameters(): void
    {
        $caught = null;

        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION,
                'message' => 'public falsey values',
                'parameters' => [
                    'status' => 0,
                    'retry' => false,
                    'reason' => '',
                    'detail' => null,
                ],
            ]);
        } catch (Exception $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
        $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION, $caught::class);
        $this->assertSame(0, $caught->status);
        $this->assertFalse($caught->retry);
        $this->assertSame('', $caught->reason);
        $this->assertNull($caught->detail);
    }

    public function testItReconstructsOptionalVariadicAndInheritedParameters(): void
    {
        $payloads = [
            [
                'exception' => ConcurrentProcessExceptionFixtures::HIDDEN_OPTIONAL_EXCEPTION,
                'message' => 'status=7',
                'parameters' => ['status' => 0],
                'expectedMessage' => 'status=0',
            ],
            [
                'exception' => ConcurrentProcessExceptionFixtures::VARIADIC_EXCEPTION,
                'message' => 'context:first,second',
                'parameters' => ['context' => 'context'],
                'expectedMessage' => 'context:',
            ],
            [
                'exception' => ConcurrentProcessExceptionFixtures::INHERITED_PUBLIC_EXCEPTION,
                'message' => 'status=7',
                'parameters' => ['status' => 7],
                'expectedMessage' => 'status=7',
            ],
        ];

        foreach ($payloads as $payload) {
            $caught = null;

            try {
                $this->decodePayload(['successful' => false, ...$payload]);
            } catch (Exception $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
            $this->assertSame($payload['exception'], $caught::class);
            $this->assertSame($payload['expectedMessage'], $caught->getMessage());
        }
    }

    public function testItReconstructsNativeDeclaredExceptionConstructors(): void
    {
        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => ErrorException::class,
                'message' => 'original message',
                'parameters' => [
                    'message' => '',
                    'code' => 0,
                    'severity' => E_ERROR,
                    'filename' => null,
                    'line' => null,
                    'previous' => null,
                ],
            ]);
            $this->fail('Expected the transported exception to be thrown.');
        } catch (ErrorException $exception) {
            $this->assertSame('', $exception->getMessage());
            $this->assertSame(E_ERROR, $exception->getSeverity());
        }
    }

    public function testItReconstructsZeroArgumentAndStoredVariadicExceptions(): void
    {
        $payloads = [
            ConcurrentProcessExceptionFixtures::ZERO_ARGUMENT_EXCEPTION => 'argumentCount',
            ConcurrentProcessExceptionFixtures::TYPED_STORED_VARIADIC_EXCEPTION => 'details',
            ConcurrentProcessExceptionFixtures::UNTYPED_STORED_VARIADIC_EXCEPTION => 'details',
        ];

        foreach ($payloads as $exceptionClass => $property) {
            $caught = null;

            try {
                $this->decodePayload([
                    'successful' => false,
                    'exception' => $exceptionClass,
                    'message' => 'remote failure',
                    'parameters' => [],
                ]);
            } catch (Exception $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
            $this->assertSame($exceptionClass, $caught::class);
            $this->assertSame($property === 'argumentCount' ? 0 : [], $caught->{$property});
        }
    }

    public function testItContainsConstructorFailuresDuringReconstruction(): void
    {
        $caught = null;

        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => ConcurrentProcessExceptionFixtures::MISMATCHED_PUBLIC_PROPERTY_EXCEPTION,
                'message' => 'status=5',
                'parameters' => ['status' => 'v5'],
            ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected exception reconstruction to fail.');
        $this->assertSame('status=5', $caught->getMessage());
        $this->assertInstanceOf(TypeError::class, $caught->getPrevious());
    }

    public function testItContainsUnavailableExceptionClassesDuringReconstruction(): void
    {
        $caught = null;

        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => 'Missing\SerializedClosureException',
                'message' => 'remote failure',
                'parameters' => [],
            ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected exception reconstruction to fail.');
        $this->assertSame('remote failure', $caught->getMessage());
        $this->assertInstanceOf(RuntimeException::class, $caught->getPrevious());
        $this->assertSame(
            'The transported exception class [Missing\SerializedClosureException] is not an available Throwable.',
            $caught->getPrevious()->getMessage(),
        );
    }

    public function testItReconstructsTheMaximumExceptionParameterDepth(): void
    {
        $value = $this->nestedValue(510);
        $caught = null;

        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => ConcurrentProcessExceptionFixtures::PUBLIC_VALUE_EXCEPTION,
                'message' => 'public value',
                'parameters' => ['value' => $value],
            ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
        $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_VALUE_EXCEPTION, $caught::class);
        $this->assertSame($value, $caught->value);
    }

    /**
     * Decode an ordinary serialized result.
     */
    private function decodeResult(mixed $result): mixed
    {
        return $this->decodePayload([
            'successful' => true,
            'result' => base64_encode(serialize($result)),
        ]);
    }

    /**
     * Decode the given response payload.
     *
     * @param array<string, mixed> $payload
     */
    private function decodePayload(array $payload): mixed
    {
        $key = 'test-envelope-key';
        $encoded = base64_encode(Json::encode($payload));
        $signature = hash_hmac('sha256', $encoded, $key);

        return SerializedClosureResult::decode(SerializedClosureResult::parseEnvelope(
            "\n@@HYPERVEL-CONCURRENCY-V1@@{$encoded}@@{$signature}@@\n",
            $key,
        ));
    }

    /**
     * Build a value with the given number of array containers.
     */
    private function nestedValue(int $containers): array
    {
        $value = ['leaf'];

        for ($depth = 1; $depth < $containers; ++$depth) {
            $value = [$value];
        }

        return $value;
    }
}
