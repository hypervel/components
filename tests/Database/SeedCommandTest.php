<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
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

class SeedCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        parent::tearDown();
    }

    public function testHandle()
    {
        $seeder = m::mock(Seeder::class);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();
        $seeder->shouldReceive('__invoke')->once()->andReturnUsing(function () {
            Assert::assertSame(
                'sqlite',
                CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
                'Seed connection should be set in Context during seeder invocation',
            );
        });

        $resolver = m::mock(ConnectionResolverInterface::class);

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            'DatabaseSeeder' => $seeder,
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        $command->run(
            new ArrayInput(['--force' => true, '--database' => 'sqlite']),
            new NullOutput,
        );

        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'Context should be cleared after seeding completes',
        );
    }

    public function testWithoutModelEvents()
    {
        $instance = new UserWithoutModelEventsSeeder;

        $seeder = m::mock($instance);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();

        $resolver = m::mock(ConnectionResolverInterface::class);

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
            new NullOutput,
        );

        Assert::assertSame($dispatcher, Model::getEventDispatcher());
    }

    public function testHandleRestoresPreviousConnectionWhenSeederThrows()
    {
        // Simulate a pre-existing Context override (e.g., from an outer
        // migrator run) — seeding should restore that exact value if the
        // seeder throws, not leak 'sqlite' or clear it entirely.
        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'pgsql');

        $resolver = m::mock(ConnectionResolverInterface::class);

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            ThrowingSeeder::class => new ThrowingSeeder,
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
                new NullOutput,
            );

            self::fail('Expected the seeder to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('Seeder failed', $exception->getMessage());
        }

        Assert::assertSame(
            'pgsql',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'Context should be restored to the pre-seed value even on exception',
        );
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

        $code = $command->run(new ArrayInput([]), new NullOutput);

        Assert::assertSame(Command::FAILURE, $code);
    }

    public function testHandleWithoutDatabaseOptionUsesResolverDefault()
    {
        // Regression for the SeedCommand::getDatabase() change: when no
        // --database is given, SeedCommand should read the current default
        // from the resolver (which respects an active Context override)
        // rather than going straight to config. This lets an outer scope's
        // Context override flow through to db:seed correctly.
        $seeder = m::mock(Seeder::class);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();
        $seeder->shouldReceive('__invoke')->once()->andReturnUsing(function () {
            Assert::assertSame(
                'tenant_reporting',
                CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
                'Seeder should run with the resolver-derived default in Context',
            );
        });

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->once()->andReturn('tenant_reporting');

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            'DatabaseSeeder' => $seeder,
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        $command->run(new ArrayInput(['--force' => true]), new NullOutput);
    }

    public function testHandleDoesNotMutateConfigDatabaseDefault()
    {
        // Regression: db:seed must use Context, not config mutation. config
        // ('database.default') should be untouched after a seed run.
        $seeder = m::mock(Seeder::class);
        $seeder->shouldReceive('setContainer')->once()->andReturnSelf();
        $seeder->shouldReceive('setCommand')->once()->andReturnSelf();
        $seeder->shouldReceive('__invoke')->once();

        $resolver = m::mock(ConnectionResolverInterface::class);

        $config = new \Hypervel\Config\Repository(['database' => ['default' => 'pgsql']]);

        $app = new ApplicationDatabaseSeedStub([
            ConnectionResolverInterface::class => $resolver,
            'DatabaseSeeder' => $seeder,
            'config' => $config,
        ]);

        $command = new SeedCommand($resolver);
        $command->setHypervel($app);

        $command->run(
            new ArrayInput(['--force' => true, '--database' => 'sqlite']),
            new NullOutput,
        );

        Assert::assertSame(
            'pgsql',
            $config->get('database.default'),
            'db:seed must not mutate config("database.default")',
        );
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
