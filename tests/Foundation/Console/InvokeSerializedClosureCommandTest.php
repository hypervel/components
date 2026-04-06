<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 * @coversNothing
 */
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
        $this->assertSame('Hello, World!', unserialize($result['result']));
    }

    public function testItCanInvokeSerializedClosureFromEnvironment(): void
    {
        $_SERVER['HYPERVEL_INVOKABLE_CLOSURE'] = base64_encode(
            serialize(new SerializableClosure(static fn () => 'From Environment'))
        );

        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [], $output);

        /** @var array{successful: bool, result: string} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertTrue($result['successful']);
        $this->assertSame('From Environment', unserialize($result['result']));

        unset($_SERVER['HYPERVEL_INVOKABLE_CLOSURE']);
    }

    public function testItReturnsNullWhenNoClosureIsProvided(): void
    {
        $output = new BufferedOutput;

        Artisan::call('invoke-serialized-closure', [], $output);

        /** @var array{successful: bool, result: string} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertTrue($result['successful']);
        $this->assertNull(unserialize($result['result']));
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

        /** @var array{successful: bool, exception: string, message: string} $result */
        $result = json_decode($output->fetch(), true);

        $this->assertFalse($result['successful']);
        $this->assertSame(RuntimeException::class, $result['exception']);
        $this->assertSame('Test exception', $result['message']);
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
