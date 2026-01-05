<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ManuallyFailedException;
use Hypervel\Testbench\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class CommandTest extends TestCase
{
    public function testFailWithNullThrowsManuallyFailedExceptionWithDefaultMessage(): void
    {
        $command = new CommandTestStubCommand();

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Command failed manually.');

        $command->fail(null);
    }

    public function testFailWithStringThrowsManuallyFailedExceptionWithMessage(): void
    {
        $command = new CommandTestStubCommand();

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Custom failure message');

        $command->fail('Custom failure message');
    }

    public function testFailWithThrowableRethrowsTheSameException(): void
    {
        $command = new CommandTestStubCommand();
        $exception = new RuntimeException('Original exception');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Original exception');

        $command->fail($exception);
    }

    public function testFailWithManuallyFailedExceptionRethrowsIt(): void
    {
        $command = new CommandTestStubCommand();
        $exception = new ManuallyFailedException('Pre-created failure');

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Pre-created failure');

        $command->fail($exception);
    }
}

class CommandTestStubCommand extends Command
{
    protected ?string $signature = 'test:stub';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
