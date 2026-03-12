<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationFreshCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        FreshCommand::prohibit(false);
        Application::setInstance(null);

        parent::tearDown();
    }

    public function testFreshCommandDropsTablesMigratesAndSeeds()
    {
        $app = new ApplicationDatabaseFreshStub();
        $dispatcher = $app->instance(Dispatcher::class, m::mock(Dispatcher::class)->shouldIgnoreMissing());
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(DatabaseRefreshed::class));

        $callCount = 0;
        $command->expects($this->exactly(2))->method('call')->willReturnCallback(function (string $name, array $arguments) use (&$callCount) {
            ++$callCount;

            if ($callCount === 1) {
                $this->assertSame('migrate', $name);
                $this->assertSame([
                    '--database' => 'sqlite',
                    '--force' => true,
                ], $arguments);

                return 0;
            }

            $this->assertSame('db:seed', $name);
            $this->assertSame([
                '--database' => 'sqlite',
                '--class' => 'Database\Seeders\CustomSeeder',
                '--force' => true,
            ], $arguments);

            return 0;
        });
        $command->expects($this->once())->method('callSilent')->with('db:wipe', [
            '--database' => 'sqlite',
            '--drop-views' => true,
            '--force' => true,
        ])->willReturn(0);

        $this->runCommand($command, ['--database' => 'sqlite', '--drop-views' => true, '--seed' => true, '--seeder' => 'Database\Seeders\CustomSeeder']);
    }

    protected function runCommand($command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class ApplicationDatabaseFreshStub extends Application
{
    public function __construct(array $data = [])
    {
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->andReturn(true);
        $mutex->shouldReceive('release')->andReturn(true);
        $this->instance(CommandMutex::class, $mutex);
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
