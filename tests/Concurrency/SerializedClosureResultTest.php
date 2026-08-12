<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use ErrorException;
use Exception;
use Hypervel\Concurrency\SerializedClosureResult;
use Hypervel\Support\Json;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\TestCase;
use JsonException;
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

    public function testItIgnoresAppendedGzipOutput(): void
    {
        $this->assertSame(
            'result',
            $this->decodePayload([
                'successful' => true,
                'result' => base64_encode(serialize('result')),
            ], "\x1f\x8bcompressed-output")
        );
    }

    public function testItRejectsMalformedJsonOutput(): void
    {
        $this->expectException(JsonException::class);

        SerializedClosureResult::decode('{malformed');
    }

    public function testItRejectsInvalidResponseEnvelopes(): void
    {
        $payloads = [
            'scalar' => 'invalid',
            'missing status' => [],
            'non-boolean status' => ['successful' => 1],
            'non-string exception' => ['successful' => false, 'exception' => []],
            'non-string message' => ['successful' => false, 'message' => []],
            'non-array parameters' => ['successful' => false, 'parameters' => 'invalid'],
        ];

        foreach ($payloads as $description => $payload) {
            $caught = null;

            try {
                SerializedClosureResult::decode(Json::encode($payload));
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, "Expected the {$description} response envelope to be rejected.");
            $this->assertSame('Invalid serialized closure response envelope.', $caught->getMessage());
        }
    }

    public function testItRejectsMalformedEncodedAndSerializedResults(): void
    {
        foreach ([
            'base64' => '*not-base64*',
            'serialized value' => base64_encode('not-serialized'),
        ] as $description => $result) {
            $caught = null;

            try {
                $this->decodePayload([
                    'successful' => true,
                    'result' => $result,
                ]);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, "Expected the malformed {$description} to be rejected.");
            $this->assertSame('Unable to decode the serialized closure result.', $caught->getMessage());
        }
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

    public function testItRejectsNonThrowableExceptionClassesBeforeConstruction(): void
    {
        NonThrowableConstructorProbe::$constructed = false;
        $caught = null;

        try {
            $this->decodePayload([
                'successful' => false,
                'exception' => NonThrowableConstructorProbe::class,
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
            'The transported exception class [' . NonThrowableConstructorProbe::class . '] is not an available Throwable.',
            $caught->getPrevious()->getMessage(),
        );
        $this->assertFalse(NonThrowableConstructorProbe::$constructed);
    }

    public function testItUsesTheGenericFailureFallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Serialized closure execution failed.');

        $this->decodePayload(['successful' => false]);
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
    private function decodePayload(array $payload, string $suffix = ''): mixed
    {
        return SerializedClosureResult::decode(Json::encode($payload) . $suffix);
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

class NonThrowableConstructorProbe
{
    public static bool $constructed = false;

    /**
     * Create a new non-Throwable constructor probe.
     */
    public function __construct()
    {
        self::$constructed = true;
    }
}
