<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Database\DatabaseManager;
use Hypervel\Support\Facades\Facade;
use Hypervel\Testing\Concerns\TestDatabases;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class TestDatabasesTest extends TestCase
{
    private mixed $originalParallelTesting;

    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();

        Container::setInstance($container = new Container);
        Facade::setFacadeApplication($container);

        $container->singleton('config', fn () => m::mock(Config::class)
            ->shouldReceive('get')
            ->once()
            ->with('database.default', null)
            ->andReturn('mysql')
            ->getMock());

        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = 1;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        parent::tearDown();
    }

    public function testSwitchToDatabaseWithoutUrl()
    {
        $container = Container::getInstance();

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('purge')->once();
        $container->instance('db', $db);

        $config = $container->make('config');
        $config->shouldReceive('get')
            ->once()
            ->with('database.connections.mysql.url', false)
            ->andReturn(false);

        $config->shouldReceive('set')
            ->once()
            ->with('database.connections.mysql.database', 'my_database_test_1');

        $this->switchToDatabase('my_database_test_1');
    }

    #[DataProvider('databaseUrls')]
    public function testSwitchToDatabaseWithUrl(string $testDatabase, string $url, string $testUrl)
    {
        $container = Container::getInstance();

        $db = m::mock(DatabaseManager::class);
        $db->shouldReceive('purge')->once();
        $container->instance('db', $db);

        $config = $container->make('config');
        $config->shouldReceive('get')
            ->once()
            ->with('database.connections.mysql.url', false)
            ->andReturn($url);

        $config->shouldReceive('set')
            ->once()
            ->with('database.connections.mysql.url', $testUrl);

        $this->switchToDatabase($testDatabase);
    }

    public static function databaseUrls(): array
    {
        return [
            [
                'my_database_test_1',
                'mysql://root:@127.0.0.1/my_database?charset=utf8mb4',
                'mysql://root:@127.0.0.1/my_database_test_1?charset=utf8mb4',
            ],
            [
                'my_database_test_1',
                'mysql://my-user:@localhost/my_database',
                'mysql://my-user:@localhost/my_database_test_1',
            ],
            [
                'my-database_test_1',
                'postgresql://my_database_user:@127.0.0.1/my-database?charset=utf8',
                'postgresql://my_database_user:@127.0.0.1/my-database_test_1?charset=utf8',
            ],
        ];
    }

    protected function switchToDatabase(string $database): void
    {
        $instance = new class {
            use TestDatabases;
        };

        $method = new ReflectionMethod($instance, 'switchToDatabase');
        $method->invoke($instance, $database);
    }
}
