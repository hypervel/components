<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

use ErrorException;
use Exception;
use Hypervel\Testbench\Foundation\Process\ProcessResult;
use Hypervel\Tests\Foundation\Console\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\TestCase;
use JsonException;
use Mockery as m;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;
use TypeError;

class ProcessResultTest extends TestCase
{
    public function testItReturnsBinaryResultsLosslessly(): void
    {
        $result = $this->processResultFor([
            'successful' => true,
            'result' => base64_encode(serialize("binary-\xFF\x00\x8B")),
        ]);

        $this->assertSame("binary-\xFF\x00\x8B", $result->output());
    }

    public function testItIgnoresAppendedGzipOutput(): void
    {
        $result = $this->processResultFor([
            'successful' => true,
            'result' => base64_encode(serialize('result')),
        ], "\x1f\x8bcompressed-output");

        $this->assertSame('result', $result->output());
    }

    public function testItRejectsMalformedJsonOutput(): void
    {
        $this->expectException(JsonException::class);

        $this->processResultForOutput('{malformed')->output();
    }

    public function testItRejectsInvalidResponseEnvelopes(): void
    {
        $outputs = [
            'scalar' => json_encode('invalid', JSON_THROW_ON_ERROR),
            'missing status' => json_encode([], JSON_THROW_ON_ERROR),
            'non-boolean status' => json_encode(['successful' => 1], JSON_THROW_ON_ERROR),
            'non-string exception' => json_encode(['successful' => false, 'exception' => []], JSON_THROW_ON_ERROR),
            'non-string message' => json_encode(['successful' => false, 'message' => []], JSON_THROW_ON_ERROR),
            'non-array parameters' => json_encode(['successful' => false, 'parameters' => 'invalid'], JSON_THROW_ON_ERROR),
        ];

        foreach ($outputs as $description => $output) {
            try {
                $this->processResultForOutput($output)->output();
                $this->fail("Expected the {$description} response envelope to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid remote process response envelope.', $exception->getMessage());
            }
        }
    }

    public function testItRejectsMalformedBase64Results(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to decode the remote process result.');

        $this->processResultFor([
            'successful' => true,
            'result' => '*not-base64*',
        ])->output();
    }

    public function testItRejectsMalformedSerializedResults(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to decode the remote process result.');

        $this->processResultFor([
            'successful' => true,
            'result' => base64_encode('not-serialized'),
        ])->output();
    }

    public function testItPreservesFalseResults(): void
    {
        $result = $this->processResultFor([
            'successful' => true,
            'result' => base64_encode(serialize(false)),
        ]);

        $this->assertFalse($result->output());
    }

    public function testItPreservesPublicFalseyExceptionParameters(): void
    {
        $result = $this->processResultFor([
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

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION, $exception::class);
            $this->assertSame(0, $exception->status);
            $this->assertFalse($exception->retry);
            $this->assertSame('', $exception->reason);
            $this->assertNull($exception->detail);
        }
    }

    public function testItUsesInaccessibleOptionalDefaults(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::HIDDEN_OPTIONAL_EXCEPTION,
            'message' => 'status=7',
            'parameters' => ['status' => 0],
        ]);

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::HIDDEN_OPTIONAL_EXCEPTION, $exception::class);
            $this->assertSame('status=0', $exception->getMessage());
        }
    }

    public function testItReconstructsNamedVariadicAndInheritedParameters(): void
    {
        $variadic = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::VARIADIC_EXCEPTION,
            'message' => 'context:first,second',
            'parameters' => ['context' => 'context'],
        ]);

        try {
            $variadic->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::VARIADIC_EXCEPTION, $exception::class);
            $this->assertSame('context:', $exception->getMessage());
        }

        $inherited = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::INHERITED_PUBLIC_EXCEPTION,
            'message' => 'status=7',
            'parameters' => ['status' => 7],
        ]);

        try {
            $inherited->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::INHERITED_PUBLIC_EXCEPTION, $exception::class);
            $this->assertSame('status=7', $exception->getMessage());
        }
    }

    public function testItReconstructsNativeDeclaredExceptionConstructors(): void
    {
        $result = $this->processResultFor([
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

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (ErrorException $exception) {
            $this->assertSame('', $exception->getMessage());
            $this->assertSame(E_ERROR, $exception->getSeverity());
        }
    }

    public function testItReconstructsZeroArgumentAndVariadicExceptionsWithoutSyntheticArguments(): void
    {
        $zero = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::ZERO_ARGUMENT_EXCEPTION,
            'message' => 'zero arguments',
            'parameters' => [],
        ]);

        try {
            $zero->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::ZERO_ARGUMENT_EXCEPTION, $exception::class);
            $this->assertSame(0, $exception->argumentCount);
        }

        foreach ([
            ConcurrentProcessExceptionFixtures::TYPED_STORED_VARIADIC_EXCEPTION,
            ConcurrentProcessExceptionFixtures::UNTYPED_STORED_VARIADIC_EXCEPTION,
        ] as $exceptionClass) {
            $result = $this->processResultFor([
                'successful' => false,
                'exception' => $exceptionClass,
                'message' => 'count=2',
                'parameters' => [],
            ]);

            try {
                $result->output();
                $this->fail('Expected the transported exception to be thrown.');
            } catch (Exception $exception) {
                $this->assertSame($exceptionClass, $exception::class);
                $this->assertSame([], $exception->details);
                $this->assertSame('count=0', $exception->getMessage());
            }
        }
    }

    public function testItContainsConstructorTypeErrorsDuringReconstruction(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::MISMATCHED_PUBLIC_PROPERTY_EXCEPTION,
            'message' => 'status=5',
            'parameters' => ['status' => 'v5'],
        ]);

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('status=5', $exception->getMessage());
            $this->assertInstanceOf(TypeError::class, $exception->getPrevious());
        }
    }

    public function testItContainsUnavailableExceptionClassesDuringReconstruction(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => 'Missing\RemoteProcessException',
            'message' => 'remote failure',
            'parameters' => [],
        ]);

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('remote failure', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testItRejectsNonThrowableExceptionClasses(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => stdClass::class,
            'message' => 'remote failure',
            'parameters' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote failure');

        $result->output();
    }

    public function testItReconstructsGenericMessageFallbacks(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => RuntimeException::class,
            'message' => 'original message',
            'parameters' => ['message' => 'original message'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original message');

        $result->output();
    }

    /**
     * Create a closure process result for the given response envelope.
     *
     * @param array<string, mixed> $payload
     */
    private function processResultFor(array $payload, string $suffix = ''): ProcessResult
    {
        return $this->processResultForOutput(json_encode($payload, JSON_THROW_ON_ERROR) . $suffix);
    }

    /**
     * Create a closure process result for the given output.
     */
    private function processResultForOutput(string $output): ProcessResult
    {
        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn($output);

        return new ProcessResult($process, static fn () => null);
    }
}
