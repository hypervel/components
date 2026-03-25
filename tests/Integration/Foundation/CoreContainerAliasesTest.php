<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CoreContainerAliasesTest extends TestCase
{
    public function testItCanResolveCoreContainerAliases()
    {
        // Hypervel uses a dedicated ConnectionResolver (not DatabaseManager) for connection
        // resolution, and tests override it with DatabaseConnectionResolver for coroutine safety.
        $this->assertInstanceOf(DatabaseConnectionResolver::class, $this->app->make(ConnectionResolverInterface::class));
    }
}
