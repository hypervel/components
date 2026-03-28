<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CoreContainerAliasesTest extends TestCase
{
    public function testConnectionResolverInterfaceResolvesToDatabaseManager()
    {
        // ConnectionResolverInterface aliases to 'db' (DatabaseManager), matching Laravel.
        $this->assertInstanceOf(DatabaseManager::class, $this->app->make(ConnectionResolverInterface::class));
    }

    public function testDbResolverResolvesToTestingConnectionResolver()
    {
        // The internal 'db.resolver' binding is overridden in tests with the
        // testing resolver that caches connections statically.
        $this->assertInstanceOf(DatabaseConnectionResolver::class, $this->app->make('db.resolver'));
    }
}
