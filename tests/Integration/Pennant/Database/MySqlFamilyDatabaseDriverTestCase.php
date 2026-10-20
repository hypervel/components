<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connectors\MariaDbConnector;
use Hypervel\Database\Connectors\MySqlConnector;
use Hypervel\Pennant\Feature;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Pdo\Mysql;

abstract class MySqlFamilyDatabaseDriverTestCase extends DatabaseDriverTestCase
{
    #[DefineEnvironment('countingFoundRowsInTheConnectionOptions')]
    public function testConcurrentWinnerIsReturnedWhenTheConnectionOptionsCountFoundRows(): void
    {
        Feature::define('foo', fn () => 'candidate');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', Feature::for('tim')->value('foo'));
    }

    /**
     * Count matched rather than changed rows through the connection options.
     */
    protected function countingFoundRowsInTheConnectionOptions(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('database.connections.' . $config->string('database.default') . '.options', [Mysql::ATTR_FOUND_ROWS => true]);
    }

    #[DefineEnvironment('countingFoundRowsInABoundConnector')]
    public function testConcurrentWinnerIsReturnedWhenABoundConnectorCountsFoundRows(): void
    {
        Feature::define('foo', fn () => 'candidate');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', Feature::for('tim')->value('foo'));
    }

    /**
     * Count matched rather than changed rows through a bound connector's default options.
     */
    protected function countingFoundRowsInABoundConnector(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $driver = $config->string('database.connections.' . $config->string('database.default') . '.driver');

        // Environment methods run before RequiresDatabase skips the test on other drivers.
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $app->bind("db.connector.{$driver}", function () use ($driver) {
            $connector = $driver === 'mariadb' ? new MariaDbConnector : new MySqlConnector;
            $connector->setDefaultOptions($connector->getDefaultOptions() + [Mysql::ATTR_FOUND_ROWS => true]);

            return $connector;
        });
    }
}
