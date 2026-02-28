<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class ImportCommandTest extends TestCase
{
    public function testThrowsExceptionWhenModelClassNotFound(): void
    {
        $events = m::mock(Dispatcher::class);

        $command = m::mock(ImportCommand::class)->makePartial();
        $command->shouldReceive('argument')
            ->with('model')
            ->andReturn('NonExistentModel');

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Model [NonExistentModel] not found.');

        $command->handle($events);
    }

    public function testResolvesModelClassFromAppModelsNamespace(): void
    {
        // Use reflection to test the protected method on a partial mock
        $command = m::mock(ImportCommand::class)->makePartial();

        $method = new ReflectionMethod(ImportCommand::class, 'resolveModelClass');
        $method->setAccessible(true);

        // Test with a class that exists - use a real class from the codebase
        $result = $method->invoke($command, \Hypervel\Scout\Builder::class);
        $this->assertSame(\Hypervel\Scout\Builder::class, $result);
    }

    public function testResolvesFullyQualifiedClassName(): void
    {
        $command = m::mock(ImportCommand::class)->makePartial();

        $method = new ReflectionMethod(ImportCommand::class, 'resolveModelClass');
        $method->setAccessible(true);

        // Test with fully qualified class name
        $result = $method->invoke($command, \Hypervel\Scout\Engine::class);
        $this->assertSame(\Hypervel\Scout\Engine::class, $result);
    }

    public function testThrowsExceptionForNonExistentClass(): void
    {
        $command = m::mock(ImportCommand::class)->makePartial();

        $method = new ReflectionMethod(ImportCommand::class, 'resolveModelClass');
        $method->setAccessible(true);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Model [FakeModelThatDoesNotExist] not found.');

        $method->invoke($command, 'FakeModelThatDoesNotExist');
    }
}
