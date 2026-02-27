<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Console\Application as Artisan;
use Hypervel\Console\Command;
use Hypervel\Console\ManuallyFailedException;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class CommandManualFailTest extends TestCase
{
    protected function setUp(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([
                FailingCommandStub::class,
            ]);
        });

        parent::setUp();
    }

    public function testFailArtisanCommandManually()
    {
        $this->artisan('app:fail')->assertFailed();
    }

    public function testCreatesAnExceptionFromString()
    {
        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Whoops!');
        $command = new Command();
        $command->fail('Whoops!');
    }

    public function testCreatesAnExceptionFromNull()
    {
        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Command failed manually.');
        $command = new Command();
        $command->fail();
    }

    public function testThrowsTheOriginalThrowableInstance()
    {
        $original = new RuntimeException('Something went wrong.');

        try {
            $command = new Command();
            $command->fail($original);

            $this->fail('Command::fail() method must throw the original throwable instance.');
        } catch (Throwable $e) {
            $this->assertSame($original, $e);
        }
    }
}

class FailingCommandStub extends Command
{
    protected ?string $signature = 'app:fail';

    public function handle()
    {
        $this->triggerFailure();

        // This should never be reached.
        return static::SUCCESS;
    }

    protected function triggerFailure(): void
    {
        $this->fail('Whoops!');
    }
}
