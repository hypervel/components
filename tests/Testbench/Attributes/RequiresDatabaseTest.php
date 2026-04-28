<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Exception;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('database.default', 'testing')]
class RequiresDatabaseTest extends TestCase
{
    #[Test]
    public function itCanValidateMatchingDatabase(): void
    {
        $stub = new RequiresDatabase('sqlite');

        $stub->handle($this->app, function (): void {
            throw new Exception;
        });

        $this->addToAssertionCount(1);

        $stub = new RequiresDatabase(['pgsql', 'sqlite']);

        $stub->handle($this->app, function (): void {
            throw new Exception;
        });

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itCanInvalidateUnmatchedDatabase(): void
    {
        $stub = new RequiresDatabase('mysql');

        $stub->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Requires mysql to be configured for "testing" database connection'], $parameters);
        });

        $stub = new RequiresDatabase(['mysql', 'mariadb']);

        $stub->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Requires [mysql/mariadb] to be configured for "testing" database connection'], $parameters);
        });
    }

    #[Test]
    public function itCanInvalidateUnmatchedDatabaseVersion(): void
    {
        $stub = new RequiresDatabase('sqlite', '<2.0.0');

        $stub->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Requires sqlite:<2.0.0 to be configured for "testing" database connection'], $parameters);
        });
    }
}
