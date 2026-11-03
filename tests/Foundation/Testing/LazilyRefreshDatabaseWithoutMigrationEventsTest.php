<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\Attributes\WithoutMigrationEvents;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Testing\Fixtures\ConsoleKernelSpy;
use Symfony\Component\Console\Output\OutputInterface;

#[ResetRefreshDatabaseState]
#[WithoutMigrationEvents]
class LazilyRefreshDatabaseWithoutMigrationEventsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected array $connectionsToTransact = ['testing'];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.default', 'testing');
    }

    public function testMigrationQueriesAreNotDispatchedToTheTest(): void
    {
        $database = $this->app->make('db');

        $this->app->instance(KernelContract::class, $kernel = new class($database) extends ConsoleKernelSpy {
            public function __construct(protected DatabaseManager $database)
            {
            }

            public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int
            {
                $this->database->connection('testing')->select('select 2');

                return parent::call($command, $parameters, $outputBuffer);
            }
        });

        $queries = [];

        $database->listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->refreshDatabase();
        $database->connection('testing')->select('select 1');

        $this->assertSame(['select 1'], $queries);
        // The refresh state is shared across tests, so prove the migration ran here.
        $this->assertCount(1, $kernel->calls);
    }
}
