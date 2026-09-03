<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DatabaseMigrationFreshCommandTest extends TestCase
{
    public function testFreshWipesEveryPreExistingTargetThenMigratesDispatchesAndSeeds(): void
    {
        $app = $this->makeApplication();
        $dispatcher = $app->instance(Dispatcher::class, m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->byDefault()->andReturn(false);
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectPreExistingMigrationTargets($migrator, ['sqlite', 'analytics'], registeredPaths: ['/registered']);
        $operations = [];

        $command->expects($this->exactly(2))->method('callSilent')->willReturnCallback(
            function (string $name, array $arguments) use (&$operations): int {
                $this->assertSame('db:wipe', $name);
                $connection = count($operations) === 0 ? 'sqlite' : 'analytics';
                $this->assertSame([
                    '--database' => $connection,
                    '--drop-views' => true,
                    '--drop-types' => true,
                    '--force' => true,
                ], $arguments);
                $operations[] = "wipe:{$connection}";

                return 0;
            }
        );
        $command->expects($this->exactly(2))->method('call')->willReturnCallback(
            function (string $name, array $arguments) use (&$operations): int {
                if ($name === 'migrate') {
                    $this->assertSame([
                        '--database' => 'sqlite',
                        '--force' => true,
                    ], $arguments);
                    $operations[] = 'migrate';

                    return 0;
                }

                $this->assertSame('db:seed', $name);
                $this->assertSame([
                    '--database' => 'sqlite',
                    '--class' => 'Database\Seeders\CustomSeeder',
                    '--force' => true,
                ], $arguments);
                $operations[] = 'seed';

                return 0;
            }
        );
        $dispatcher->shouldReceive('hasListeners')->once()->with(DatabaseRefreshed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(DatabaseRefreshed::class))->andReturnUsing(
            function (DatabaseRefreshed $event) use (&$operations): void {
                $this->assertSame('sqlite', $event->database);
                $this->assertTrue($event->seeding);
                $operations[] = 'event';
            }
        );

        $code = $this->runCommand($command, [
            '--database' => 'sqlite',
            '--drop-views' => true,
            '--drop-types' => true,
            '--seed' => true,
            '--seeder' => 'Database\Seeders\CustomSeeder',
        ]);

        $this->assertSame(0, $code);
        $this->assertSame(['wipe:sqlite', 'wipe:analytics', 'migrate', 'event', 'seed'], $operations);
    }

    public function testReachableDatabaseWithoutMigrationRepositoryIsStillWiped(): void
    {
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectPreExistingMigrationTargets($migrator, ['sqlite'], repositoryExists: false);
        $command->expects($this->once())->method('callSilent')->with('db:wipe', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->willReturn(0);
        $command->expects($this->once())->method('call')->with('migrate', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->willReturn(0);

        $this->assertSame(0, $this->runCommand($command, ['--database' => 'sqlite']));
    }

    public function testMissingTargetIsCreatedAndVerifiedButNeverWiped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $currentConnection = null;
        $analyticsInspections = 0;

        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, 'sqlite')->andReturn(['sqlite', 'analytics']);
        $migrator->shouldReceive('usingConnection')->times(3)->andReturnUsing(
            function ($name, $callback) use (&$currentConnection) {
                $currentConnection = $name;

                try {
                    return $callback();
                } finally {
                    $currentConnection = null;
                }
            }
        );
        $migrator->shouldReceive('repositoryExists')->times(3)->andReturnUsing(
            function () use (&$currentConnection, &$analyticsInspections, $path): bool {
                if ($currentConnection === 'analytics') {
                    ++$analyticsInspections;

                    if ($analyticsInspections === 1) {
                        throw new SQLiteDatabaseDoesNotExistException($path);
                    }

                    $this->assertFileExists($path);
                }

                return true;
            }
        );
        $command->expects($this->once())->method('callSilent')->with('db:wipe', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->willReturnCallback(function () use ($path, &$analyticsInspections): int {
            $this->assertFileExists($path);
            $this->assertSame(2, $analyticsInspections);

            return 0;
        });
        $command->expects($this->once())->method('call')->with('migrate', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->willReturn(0);
        $output = new BufferedOutput;

        try {
            $this->assertSame(0, $this->runCommand($command, ['--database' => 'sqlite'], $output));
            $content = $output->fetch();
            $this->assertStringContainsString('will be wiped', $content);
            $this->assertStringContainsString('will be created', $content);
            $this->assertStringContainsString('sqlite', $content);
            $this->assertStringContainsString('analytics', $content);
            $this->assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function testDecliningProductionConfirmationCreatesAndWipesNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);
        $app = $this->makeApplication('production');
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $inspection = 0;
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, 'sqlite')->andReturn(['sqlite', 'analytics']);
        $migrator->shouldReceive('usingConnection')->twice()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->twice()->andReturnUsing(
            function () use (&$inspection, $path): bool {
                if (++$inspection === 2) {
                    throw new SQLiteDatabaseDoesNotExistException($path);
                }

                return true;
            }
        );
        $command->expects($this->never())->method('callSilent');
        $command->expects($this->never())->method('call');

        try {
            $this->assertSame(1, $this->runCommand($command, ['--database' => 'sqlite']));
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function testCreationFailurePreventsEveryWipeAndMigration(): void
    {
        $path = '/missing/database.sqlite';
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(TestableFreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->creationFailure = $failure = new RuntimeException('create failed');
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $inspection = 0;
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, 'sqlite')->andReturn(['sqlite', 'analytics']);
        $migrator->shouldReceive('usingConnection')->twice()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->twice()->andReturnUsing(
            function () use (&$inspection, $path): bool {
                if (++$inspection === 2) {
                    throw new SQLiteDatabaseDoesNotExistException($path);
                }

                return true;
            }
        );
        $command->expects($this->never())->method('callSilent');
        $command->expects($this->never())->method('call');
        $caught = null;

        try {
            $this->runCommand($command, ['--database' => 'sqlite']);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($failure, $caught);
    }

    public function testWipeFailureAbortsBeforeMigration(): void
    {
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectPreExistingMigrationTargets($migrator, ['analytics']);
        $command->expects($this->once())->method('callSilent')->willReturn(1);
        $command->expects($this->never())->method('call');
        $caught = null;

        try {
            $this->runCommand($command, ['--database' => 'sqlite']);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame('Database wipe failed for connection [analytics].', $caught?->getMessage());
    }

    public function testMigrationFailureAbortsBeforeEventAndSeeding(): void
    {
        $app = $this->makeApplication();
        $dispatcher = $app->instance(Dispatcher::class, m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->byDefault()->andReturn(false);
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectPreExistingMigrationTargets($migrator, ['sqlite']);
        $command->expects($this->once())->method('callSilent')->willReturn(0);
        $command->expects($this->once())->method('call')->with('migrate', [
            '--database' => 'sqlite',
            '--force' => true,
        ])->willReturn(1);
        $dispatcher->shouldReceive('dispatch')->never();
        $caught = null;

        try {
            $this->runCommand($command, ['--database' => 'sqlite', '--seed' => true]);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame('Migration command failed while refreshing the databases.', $caught?->getMessage());
    }

    public function testSeedFailureOccursAfterDatabaseRefreshedEventAndFailsTheCommand(): void
    {
        $app = $this->makeApplication();
        $dispatcher = $app->instance(Dispatcher::class, m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->byDefault()->andReturn(false);
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectPreExistingMigrationTargets($migrator, ['sqlite']);
        $operations = [];
        $command->expects($this->once())->method('callSilent')->willReturn(0);
        $command->expects($this->exactly(2))->method('call')->willReturnCallback(
            function (string $name) use (&$operations): int {
                $operations[] = $name;

                return $name === 'migrate' ? 0 : 1;
            }
        );
        $dispatcher->shouldReceive('hasListeners')->once()->with(DatabaseRefreshed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$operations): void {
            $operations[] = 'event';
        });
        $caught = null;

        try {
            $this->runCommand($command, ['--database' => 'sqlite', '--seed' => true]);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame('Database seeding failed after the databases were refreshed.', $caught?->getMessage());
        $this->assertSame(['migrate', 'event', 'db:seed'], $operations);
    }

    public function testUnclassifiedInspectionFailurePropagatesBeforeMutation(): void
    {
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $failure = new RuntimeException('Authentication failed.');
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, 'sqlite')->andReturn(['sqlite']);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andThrow($failure);
        $command->expects($this->never())->method('callSilent');
        $command->expects($this->never())->method('call');
        $caught = null;

        try {
            $this->runCommand($command, ['--database' => 'sqlite']);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($failure, $caught);
    }

    #[DataProvider('migrationPathProvider')]
    public function testFreshUsesTheSamePathOptionsForDiscoveryAndNestedMigrate(
        array $input,
        array $discoveryPaths,
        array $migrateArguments,
    ): void {
        $app = $this->makeApplication();
        $command = $this->getMockBuilder(FreshCommand::class)
            ->onlyMethods(['call', 'callSilent'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class)])
            ->getMock();
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->never();
        $migrator->shouldReceive('getMigrationConnections')->once()->with($discoveryPaths, 'sqlite')->andReturn(['sqlite']);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $command->expects($this->once())->method('callSilent')->willReturn(0);
        $command->expects($this->once())->method('call')->with('migrate', $migrateArguments)->willReturn(0);

        $this->assertSame(0, $this->runCommand($command, ['--database' => 'sqlite', ...$input]));
    }

    public static function migrationPathProvider(): array
    {
        return [
            'relative path' => [
                ['--path' => ['custom/migrations']],
                [__DIR__ . '/custom/migrations'],
                [
                    '--database' => 'sqlite',
                    '--path' => ['custom/migrations'],
                    '--force' => true,
                ],
            ],
            'real path' => [
                ['--path' => ['/absolute/migrations'], '--realpath' => true],
                ['/absolute/migrations'],
                [
                    '--database' => 'sqlite',
                    '--path' => ['/absolute/migrations'],
                    '--realpath' => true,
                    '--force' => true,
                ],
            ],
        ];
    }

    public function testProhibitedFreshReturnsBeforeDiscovery(): void
    {
        $app = $this->makeApplication();
        $command = new FreshCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldNotReceive('getMigrationConnections');
        FreshCommand::prohibit();

        $this->assertSame(1, $this->runCommand($command, ['--database' => 'sqlite']));
    }

    private function expectPreExistingMigrationTargets(
        Migrator $migrator,
        array $connections,
        ?string $database = 'sqlite',
        bool $repositoryExists = true,
        array $registeredPaths = [],
    ): void {
        $paths = [...$registeredPaths, __DIR__ . DIRECTORY_SEPARATOR . 'migrations'];

        $migrator->shouldReceive('paths')->once()->andReturn($registeredPaths);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, $database)->andReturn($connections);
        $migrator->shouldReceive('usingConnection')->times(count($connections))->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->times(count($connections))->andReturn($repositoryExists);
    }

    private function makeApplication(string $environment = 'development'): ApplicationDatabaseFreshStub
    {
        $app = new ApplicationDatabaseFreshStub(['env' => $environment]);
        $app->setBasePath(__DIR__);
        $app->useDatabasePath(__DIR__);

        return $app;
    }

    protected function runCommand(
        FreshCommand $command,
        array $input = [],
        ?OutputInterface $output = null,
    ): int {
        return $command->run(new ArrayInput($input), $output ?? new NullOutput);
    }
}

class ApplicationDatabaseFreshStub extends Application
{
    public function __construct(array $data = [])
    {
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }
}

class TestableFreshCommand extends FreshCommand
{
    public ?RuntimeException $creationFailure = null;

    protected function createMissingDatabase(string $connectionName, Throwable $cause): void
    {
        if ($this->creationFailure !== null) {
            throw $this->creationFailure;
        }

        parent::createMissingDatabase($connectionName, $cause);
    }
}
