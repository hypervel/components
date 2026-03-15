<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Console\Seeds\SeedCommand;
use Hypervel\Database\Console\Seeds\WithoutModelEvents;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Seeder;
use Hypervel\Events\NullDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Testing\Assert;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class SeedCommandTest extends TestCase
{
    public function testHandle()
    {
        $seeder = m::mock(Seeder::class);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();
        $seeder->shouldReceive('__invoke')->once();

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->once()->andReturn('mysql');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('sqlite');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('mysql');

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            'DatabaseSeeder' => $seeder,
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        $command->run(
            new ArrayInput(['--force' => true, '--database' => 'sqlite']),
            new NullOutput(),
        );
    }

    public function testWithoutModelEvents()
    {
        $instance = new UserWithoutModelEventsSeeder();

        $seeder = m::mock($instance);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->once()->andReturn('mysql');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('sqlite');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('mysql');

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            UserWithoutModelEventsSeeder::class => $seeder,
        ]);

        Model::setEventDispatcher($dispatcher = m::mock(\Hypervel\Contracts\Events\Dispatcher::class));

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        $command->run(
            new ArrayInput([
                '--force' => true,
                '--database' => 'sqlite',
                '--class' => UserWithoutModelEventsSeeder::class,
            ]),
            new NullOutput(),
        );

        Assert::assertSame($dispatcher, Model::getEventDispatcher());
    }

    public function testHandleRestoresPreviousConnectionWhenSeederThrows()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->once()->andReturn('pgsql');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('sqlite');
        $resolver->shouldReceive('setDefaultConnection')->once()->with('pgsql');

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            ThrowingSeeder::class => new ThrowingSeeder(),
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        try {
            $command->run(
                new ArrayInput([
                    '--force' => true,
                    '--database' => 'sqlite',
                    '--class' => ThrowingSeeder::class,
                ]),
                new NullOutput(),
            );

            self::fail('Expected the seeder to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('Seeder failed', $exception->getMessage());
        }
    }

    public function testProhibitable()
    {
        $resolver = m::mock(ConnectionResolverInterface::class);

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        SeedCommand::prohibit();

        $code = $command->run(new ArrayInput([]), new NullOutput());

        Assert::assertSame(Command::FAILURE, $code);
    }
}

class ApplicationDatabaseSeedStub extends Application
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

class UserWithoutModelEventsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run()
    {
        Assert::assertInstanceOf(NullDispatcher::class, Model::getEventDispatcher());
    }
}

class ThrowingSeeder extends Seeder
{
    public function run(): never
    {
        throw new RuntimeException('Seeder failed');
    }
}
