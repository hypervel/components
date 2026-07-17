<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Closure;
use ErrorException;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Console\Fixtures\ConcurrentProcessExceptionFixtures;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class InvokeSerializedClosureCommandTest extends TestCase
{
    public function testItCanInvokeSerializedClosureFromArgument(): void
    {
        $serializedClosure = serialize(new SerializableClosure(static fn () => 'Hello, World!'));

        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [
            'code' => $serializedClosure,
        ], $output);

        /** @var array{successful: bool, result: string} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertTrue($result['successful']);
        $this->assertSame('Hello, World!', $this->decodeResult($result));
    }

    public function testItCanInvokeSerializedClosureFromEnvironment(): void
    {
        $hadPrevious = array_key_exists('HYPERVEL_INVOKABLE_CLOSURE', $_SERVER);
        $previous = $_SERVER['HYPERVEL_INVOKABLE_CLOSURE'] ?? null;

        try {
            $_SERVER['HYPERVEL_INVOKABLE_CLOSURE'] = base64_encode(
                serialize(new SerializableClosure(static fn () => 'From Environment'))
            );

            $output = new BufferedOutput;

            Artisan::call('invoke-serialized-closure', [], $output);

            /** @var array{successful: bool, result: string} $result */
            $result = json_decode($output->fetch(), true);

            $this->assertTrue($result['successful']);
            $this->assertSame('From Environment', $this->decodeResult($result));
        } finally {
            if ($hadPrevious) {
                $_SERVER['HYPERVEL_INVOKABLE_CLOSURE'] = $previous;
            } else {
                unset($_SERVER['HYPERVEL_INVOKABLE_CLOSURE']);
            }
        }
    }

    public function testItReturnsNullWhenNoClosureIsProvided(): void
    {
        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [], $output);

        /** @var array{successful: bool, result: string} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertTrue($result['successful']);
        $this->assertNull($this->decodeResult($result));
    }

    public function testItHandlesExceptionsGracefully(): void
    {
        $serializedClosure = serialize(new SerializableClosure(
            static fn () => throw new RuntimeException('Test exception')
        ));

        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [
            'code' => $serializedClosure,
        ], $output);

        /** @var array{successful: bool, exception: string, message: string, parameters: array<string, mixed>} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('Test exception', $result['message']);
        $this->assertSame(['message' => 'Test exception'], $result['parameters']);
    }

    public function testItHandlesCustomExceptionWithParameters(): void
    {
        $serializedClosure = serialize(new SerializableClosure(
            static fn () => throw new InvokeSerializedClosureCustomParameterException('Test param')
        ));

        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [
            'code' => $serializedClosure,
        ], $output);

        /** @var array{successful: bool, exception: string, parameters: array<string, mixed>} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertFalse($result['successful']);
        $this->assertSame(InvokeSerializedClosureCustomParameterException::class, $result['exception']);
        $this->assertSame('Test param', $result['parameters']['customParam'] ?? null);
        $this->assertSame('', $result['parameters']['message'] ?? null);
    }

    public function testItTransportsBinaryResultsLosslessly(): void
    {
        $result = $this->invokeSerializedClosure(static fn () => "binary-\xFF\x00\x8B");

        $this->assertTrue($result['successful']);
        $this->assertSame("binary-\xFF\x00\x8B", $this->decodeResult($result));
    }

    public function testItContainsFailuresFromTheExceptionReporter(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')
            ->once()
            ->andThrow(new RuntimeException('reporting failed'));
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $result = $this->invokeSerializedClosure(
            static fn () => throw new RuntimeException('task failed')
        );

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('task failed', $result['message']);
    }

    public function testItSubstitutesInvalidUtf8InFailureDiagnostics(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => throw new RuntimeException("invalid \xFF message")
        );

        $this->assertFalse($result['successful']);
        $this->assertSame('invalid � message', $result['message']);
    }

    public function testItFallsBackWhenExceptionParametersCannotBeEncoded(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwResourceState()
        );

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertStringContainsString('ResourceStateException', $result['message']);
        $this->assertStringContainsString('could not be encoded', $result['message']);
        $this->assertSame(['message' => $result['message']], $result['parameters']);
    }

    public function testItFallsBackWhenExceptionParametersAreRecursive(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwRecursiveValue()
        );

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertStringContainsString('PublicValueException', $result['message']);
        $this->assertStringContainsString('could not be encoded', $result['message']);
        $this->assertSame(['message' => $result['message']], $result['parameters']);
    }

    public function testItDegradesInvalidUtf8ExceptionClassNamesWithoutChangingTheMessage(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwInvalidUtf8ClassName()
        );

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('invalid class name', $result['message']);
        $this->assertSame(['message' => 'invalid class name'], $result['parameters']);
    }

    public function testItDegradesConstructorStateThatJsonCannotPreserve(): void
    {
        foreach ([
            'throwObjectValue',
            'throwNestedObjectValue',
            'throwEnumValue',
            'throwBinaryStringValue',
        ] as $method) {
            $result = $this->invokeSerializedClosure(
                static fn () => ConcurrentProcessExceptionFixtures::$method()
            );

            $this->assertFalse($result['successful']);
            $this->assertSame(RuntimeException::class, $result['exception']);
            $this->assertSame('public value', $result['message']);
            $this->assertSame(['message' => 'public value'], $result['parameters']);
        }
    }

    public function testItPreservesJsonStableFloatParameters(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwFloatValue()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_VALUE_EXCEPTION, $result['exception']);
        $this->assertSame(['value' => 1.0], $result['parameters']);
    }

    public function testItPreservesPublicFalseyExceptionParameters(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwPublicFalseyValues()
        );

        $this->assertSame([
            'status' => 0,
            'retry' => false,
            'reason' => '',
            'detail' => null,
        ], $result['parameters']);
    }

    public function testItUsesDefaultsForInaccessibleOptionalExceptionParameters(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwOptionalMessage()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::OPTIONAL_MESSAGE_EXCEPTION, $result['exception']);
        $this->assertSame(['context' => 'context', 'message' => ''], $result['parameters']);
    }

    public function testItDegradesExceptionsWithInaccessibleRequiredParameters(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwHiddenRequired()
        );

        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('status=7', $result['message']);
        $this->assertSame(['message' => 'status=7'], $result['parameters']);
    }

    public function testItDegradesExceptionsWithUninitializedRequiredProperties(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwUninitializedPublicRequired()
        );

        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('status=7', $result['message']);
        $this->assertSame(['message' => 'status=7'], $result['parameters']);
    }

    public function testItUsesDefaultsForEntirelyInaccessibleOptionalState(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwHiddenOptional()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::HIDDEN_OPTIONAL_EXCEPTION, $result['exception']);
        $this->assertSame('status=7', $result['message']);
        $this->assertSame(['status' => 0], $result['parameters']);
    }

    public function testItOmitsInaccessibleVariadicExceptionParameters(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwVariadic()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::VARIADIC_EXCEPTION, $result['exception']);
        $this->assertSame(['context' => 'context'], $result['parameters']);
    }

    public function testItRepresentsZeroArgumentAndStoredVariadicConstructorsStructurally(): void
    {
        $zero = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwZeroArgument()
        );
        $typed = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwTypedStoredVariadic()
        );
        $untyped = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwUntypedStoredVariadic()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::ZERO_ARGUMENT_EXCEPTION, $zero['exception']);
        $this->assertSame([], $zero['parameters']);
        $this->assertSame(ConcurrentProcessExceptionFixtures::TYPED_STORED_VARIADIC_EXCEPTION, $typed['exception']);
        $this->assertSame([], $typed['parameters']);
        $this->assertSame(ConcurrentProcessExceptionFixtures::UNTYPED_STORED_VARIADIC_EXCEPTION, $untyped['exception']);
        $this->assertSame([], $untyped['parameters']);
    }

    public function testItExtractsInheritedUserlandExceptionConstructors(): void
    {
        $public = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwInheritedPublic()
        );
        $hidden = $this->invokeSerializedClosure(
            static fn () => ConcurrentProcessExceptionFixtures::throwInheritedHiddenRequired()
        );

        $this->assertSame(ConcurrentProcessExceptionFixtures::INHERITED_PUBLIC_EXCEPTION, $public['exception']);
        $this->assertSame(['status' => 7], $public['parameters']);
        $this->assertSame(RuntimeException::class, $hidden['exception']);
        $this->assertSame('status=7', $hidden['message']);
    }

    public function testItExtractsNativeDeclaredExceptionConstructors(): void
    {
        $result = $this->invokeSerializedClosure(
            static fn () => throw new ErrorException('original message', severity: E_WARNING)
        );

        $this->assertSame(ErrorException::class, $result['exception']);
        $this->assertSame('original message', $result['message']);
        $this->assertSame([
            'message' => '',
            'code' => 0,
            'severity' => E_ERROR,
            'filename' => null,
            'line' => null,
            'previous' => null,
        ], $result['parameters']);
    }

    /**
     * Invoke a serialized closure and decode its response envelope.
     *
     * @return array<string, mixed>
     */
    private function invokeSerializedClosure(Closure $closure): array
    {
        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [
            'code' => serialize(new SerializableClosure($closure)),
        ], $output);

        return json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a serialized result from a response envelope.
     *
     * @param array<string, mixed> $result
     */
    private function decodeResult(array $result): mixed
    {
        $serialized = base64_decode($result['result'], true);

        $this->assertNotFalse($serialized);

        return unserialize($serialized);
    }
}

class InvokeSerializedClosureCustomParameterException extends RuntimeException
{
    public function __construct(
        public string $customParam,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Exception with param: {$customParam}");
    }
}
