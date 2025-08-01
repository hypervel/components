<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Commands\ModelOption;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Connectors\ConnectionFactory;
use Hyperf\Database\Connectors\MySqlConnector;
use Hyperf\Database\SQLite\Connectors\SQLiteConnector;
use Hyperf\Database\SQLite\SQLiteConnection;
use Hyperf\Di\Container;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;

class ContainerStub
{
    public static function getContainer($callback = null)
    {
        $container = Mockery::mock(Container::class);
        ApplicationContext::setContainer($container);

        // Register SQLite connection resolver
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new SQLiteConnection($connection, $database, $prefix, $config);
        });

        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturnFalse();
        $container->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnFalse();
        $container->shouldReceive('get')->with('db.connector.mysql')->andReturn(new MySqlConnector());
        $container->shouldReceive('get')->with('db.connector.sqlite')->andReturn(new SQLiteConnector());
        $container->shouldReceive('get')->with('sqlite.persistent.pdo.')->andReturnNull();

        // Register SQLite connection resolver
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new SQLiteConnection($connection, $database, $prefix, $config);
        });

        $connector = new ConnectionFactory($container);

        $dbConfig = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ];

        $connection = $connector->make($dbConfig);

        if (is_callable($callback)) {
            $callback($connection);
        }

        $resolver = new ConnectionResolver(['default' => $connection]);

        $container->shouldReceive('get')->with(ConnectionResolverInterface::class)->andReturn($resolver);

        return $container;
    }

    public static function getModelOption()
    {
        $option = new ModelOption();
        $option->setWithComments(false)
            ->setRefreshFillable(true)
            ->setForceCasts(true)
            ->setInheritance('Model')
            ->setPath(__DIR__ . '/../Stubs/Model')
            ->setPool('default')
            ->setPrefix('')
            ->setWithIde(false);
        return $option;
    }

    public static function unsetContainer()
    {
        $ref = new ReflectionClass(ApplicationContext::class);
        $c = $ref->getProperty('container');
        $c->setValue(null);
    }
}
